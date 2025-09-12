use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use ext_php_rs::zend;
use crate::StreamDriver;
use mysql_async::{Pool, OptsBuilder};
use mysql_binlog_connector_rust::{
    binlog_client::BinlogClient,
    binlog_stream::BinlogStream,
    event::{
        event_data::EventData, 
        event_header::EventHeader,
        table_map_event::TableMapEvent,
        row_event::RowEvent,
    },
    column::column_value::ColumnValue,
};
use std::sync::atomic::{AtomicU32, Ordering};
use std::sync::LazyLock;

static NEXT_SERVER_ID: LazyLock<AtomicU32> = LazyLock::new(|| {
    use std::time::{SystemTime, UNIX_EPOCH};
    let timestamp = SystemTime::now().duration_since(UNIX_EPOCH)
        .unwrap_or_default().as_secs() as u32;
    // Use lower 16 bits of timestamp + random component to avoid conflicts
    AtomicU32::new((timestamp & 0xFFFF) + (rand::random::<u16>() as u32))
});

pub struct MySQLStreamDriver {
    host: String,
    port: u16,
    user: String,
    password: String,
    database: Option<String>,
    server_id: Option<u32>,
    position: u64,
    pool: Option<Pool>,
    binlog_client: Option<BinlogClient>,
    binlog_stream: Option<BinlogStream>,
    current_gtid: Option<String>,
    current_event: Option<Zval>,
    event_iterator_started: bool,
    connected: bool,
    table_map: std::collections::HashMap<u64, TableMapEvent>,
}

impl std::fmt::Debug for MySQLStreamDriver {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MySQLStreamDriver")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("user", &self.user)
            .field("database", &self.database)
            .field("server_id", &self.server_id)
            .field("position", &self.position)
            .field("connected", &self.connected)
            .finish()
    }
}

impl MySQLStreamDriver {
    pub fn new(
        host: String,
        port: u16,
        user: String,
        password: String,
        database: Option<String>,
        server_id: Option<u32>,
    ) -> Self {
        MySQLStreamDriver {
            host,
            port,
            user,
            password,
            database,
            server_id,
            position: 0,
            pool: None,
            binlog_client: None,
            binlog_stream: None,
            current_gtid: None,
            current_event: None,
            event_iterator_started: false,
            connected: false,
            table_map: std::collections::HashMap::new(),
        }
    }

    async fn validate_mysql_config(&self, pool: &Pool) -> Result<(), String> {
        let mut conn = pool.get_conn().await
            .map_err(|e| format!("Failed to get connection: {}", e))?;
        
        // Check binlog_format = ROW
        let binlog_format: String = mysql_async::prelude::Queryable::query_first(
            &mut conn, 
            "SHOW VARIABLES LIKE 'binlog_format'"
        ).await
            .map_err(|e| format!("Failed to query binlog_format: {}", e))?
            .map(|row: (String, String)| row.1)
            .unwrap_or_default();
        
        if binlog_format.to_uppercase() != "ROW" {
            return Err(format!("binlog_format must be ROW, got: {}", binlog_format));
        }
        
        // Check binlog_row_image = FULL
        let binlog_row_image: String = mysql_async::prelude::Queryable::query_first(
            &mut conn,
            "SHOW VARIABLES LIKE 'binlog_row_image'"
        ).await
            .map_err(|e| format!("Failed to query binlog_row_image: {}", e))?
            .map(|row: (String, String)| row.1)
            .unwrap_or_default();
        
        if binlog_row_image.to_uppercase() != "FULL" {
            return Err(format!("binlog_row_image must be FULL, got: {}", binlog_row_image));
        }
        
        // Check binlog_row_metadata = FULL
        let binlog_row_metadata: String = mysql_async::prelude::Queryable::query_first(
            &mut conn,
            "SHOW VARIABLES LIKE 'binlog_row_metadata'"
        ).await
            .map_err(|e| format!("Failed to query binlog_row_metadata: {}", e))?
            .map(|row: (String, String)| row.1)
            .unwrap_or_default();
        
        if binlog_row_metadata.to_uppercase() != "FULL" {
            return Err(format!("binlog_row_metadata must be FULL, got: {}", binlog_row_metadata));
        }
        
        Ok(())
    }
    
    async fn get_current_gtid(&self, pool: &Pool) -> Result<String, String> {
        let mut conn = pool.get_conn().await
            .map_err(|e| format!("Failed to get connection for GTID: {}", e))?;
        
        // Detect database type by checking version
        let version: String = mysql_async::prelude::Queryable::query_first(
            &mut conn,
            "SELECT VERSION()"
        ).await
            .map_err(|e| format!("Failed to query database version: {}", e))?
            .unwrap_or_default();
        
        let is_mariadb = version.to_lowercase().contains("mariadb");
        
        // Use appropriate GTID variable based on database type
        let gtid_query = if is_mariadb {
            "SELECT @@global.gtid_current_pos"
        } else {
            "SELECT @@global.gtid_executed"
        };
        
        let gtid_position: String = mysql_async::prelude::Queryable::query_first(
            &mut conn,
            gtid_query
        ).await
            .map_err(|e| format!("Failed to query GTID position: {}", e))?
            .unwrap_or_default();
            
        Ok(gtid_position)
    }
    
    async fn initialize_binlog_client(&mut self) -> PhpResult<()> {
        let connection_url = format!("mysql://{}:{}@{}:{}",
            self.user, self.password, self.host, self.port);
            
        let gtid_set = self.current_gtid.clone().unwrap_or_default();
        
        let mut binlog_client = BinlogClient {
            url: connection_url,
            binlog_filename: "".to_string(),
            binlog_position: 4,
            server_id: self.server_id.unwrap_or_else(|| {
                NEXT_SERVER_ID.fetch_add(1, Ordering::Relaxed)
            }) as u64,
            gtid_enabled: !gtid_set.is_empty(),
            gtid_set,
            heartbeat_interval_secs: 30,
            timeout_secs: 60,
        };
        
        // Connect to binlog stream
        let binlog_stream = binlog_client.connect().await
            .map_err(|e| PhpException::default(format!("Failed to connect to binlog: {}", e).into()))?;
            
        self.binlog_stream = Some(binlog_stream);
        self.binlog_client = Some(binlog_client);
        Ok(())
    }
    
    fn fetch_next_event(&mut self) -> PhpResult<()> {
        // Create a runtime for async operations
        let rt = tokio::runtime::Runtime::new()
            .map_err(|e| PhpException::default(format!("Failed to create Tokio runtime: {}", e).into()))?;

        rt.block_on(async {
            if let Some(ref mut stream) = self.binlog_stream {
                loop {
                    // Read next event from binlog stream
                    let (header, data) = stream.read().await
                        .map_err(|e| PhpException::default(format!("Failed to read binlog event: {}", e).into()))?;

                    match data {
                        // Handle table map events to maintain column metadata
                        EventData::TableMap(table_map_event) => {
                            self.table_map.insert(table_map_event.table_id, table_map_event.clone());
                            // Continue to next event, don't return table map events to PHP
                            continue;
                        },
                        
                        // Handle row events that we want to convert to PHP events
                        EventData::WriteRows(write_rows_event) => {
                            if let Some(table_map) = self.table_map.get(&write_rows_event.table_id) {
                                // Convert to InsertEvent  
                                for row in &write_rows_event.rows {
                                    match self.create_insert_event_from_binlog(
                                        &header, 
                                        table_map, 
                                        row
                                    ) {
                                        Ok(event_obj) => {
                                            self.current_event = Some(event_obj);
                                            return Ok(());
                                        }
                                        Err(e) => {
                                            // Log the error but continue - maybe the class loading will work later
                                            eprintln!("Failed to create InsertEvent: {:?}", e);
                                            self.current_event = None;
                                            return Ok(());
                                        }
                                    }
                                }
                            }
                            // Skip if no table map found
                            continue;
                        },
                        
                        EventData::UpdateRows(update_rows_event) => {
                            if let Some(table_map) = self.table_map.get(&update_rows_event.table_id) {
                                // Convert to UpdateEvent
                                for (before_row, after_row) in &update_rows_event.rows {
                                    let event_obj = self.create_update_event_from_binlog(
                                        &header,
                                        table_map,
                                        before_row,
                                        after_row
                                    )?;
                                    self.current_event = Some(event_obj);
                                    return Ok(());
                                }
                            }
                            // Skip if no table map found
                            continue;
                        },
                        
                        EventData::DeleteRows(delete_rows_event) => {
                            if let Some(table_map) = self.table_map.get(&delete_rows_event.table_id) {
                                // Convert to DeleteEvent
                                for row in &delete_rows_event.rows {
                                    let event_obj = self.create_delete_event_from_binlog(
                                        &header,
                                        table_map,
                                        row
                                    )?;
                                    self.current_event = Some(event_obj);
                                    return Ok(());
                                }
                            }
                            // Skip if no table map found
                            continue;
                        },
                        
                        // Skip all other event types
                        _ => {
                            continue;
                        }
                    }
                }
            } else {
                // No binlog stream available, set current_event to None
                self.current_event = None;
                Ok(())
            }
        })
    }
    
    fn create_insert_event_from_binlog(
        &self,
        header: &EventHeader,
        table_map: &TableMapEvent,
        row: &RowEvent
    ) -> PhpResult<Zval> {
        let timestamp = header.timestamp as i64;
        let checkpoint = format!("{}:{}", header.next_event_position, timestamp);
        
        let after_data = self.create_data_object_from_row(table_map, row)?;
        
        self.create_event(
            "DataAccessKit\\Replication\\InsertEvent",
            "INSERT",
            timestamp as i32,
            &checkpoint,
            &table_map.database_name,
            &table_map.table_name,
            None,
            Some(after_data)
        ).map(|opt| opt.unwrap())
    }
    
    fn create_update_event_from_binlog(
        &self,
        header: &EventHeader,
        table_map: &TableMapEvent,
        before_row: &RowEvent,
        after_row: &RowEvent
    ) -> PhpResult<Zval> {
        let timestamp = header.timestamp as i64;
        let checkpoint = format!("{}:{}", header.next_event_position, timestamp);
        
        let before_data = self.create_data_object_from_row(table_map, before_row)?;
        let after_data = self.create_data_object_from_row(table_map, after_row)?;
        
        self.create_event(
            "DataAccessKit\\Replication\\UpdateEvent",
            "UPDATE", 
            timestamp as i32,
            &checkpoint,
            &table_map.database_name,
            &table_map.table_name,
            Some(before_data),
            Some(after_data)
        ).map(|opt| opt.unwrap())
    }
    
    fn create_delete_event_from_binlog(
        &self,
        header: &EventHeader,
        table_map: &TableMapEvent,
        row: &RowEvent
    ) -> PhpResult<Zval> {
        let timestamp = header.timestamp as i64;
        let checkpoint = format!("{}:{}", header.next_event_position, timestamp);
        
        let before_data = self.create_data_object_from_row(table_map, row)?;
        
        self.create_event(
            "DataAccessKit\\Replication\\DeleteEvent",
            "DELETE",
            timestamp as i32,
            &checkpoint,
            &table_map.database_name,
            &table_map.table_name,
            Some(before_data),
            None
        ).map(|opt| opt.unwrap())
    }
    
    fn create_data_object_from_row(&self, _table_map: &TableMapEvent, row: &RowEvent) -> PhpResult<Zval> {
        use std::collections::HashMap;
        
        // Create a PHP stdClass object using column names from table map
        let mut map = HashMap::new();
        
        // Since we don't have column names in the table map event from this crate,
        // we'll use column indices as keys for now. In a full implementation,
        // you'd need to query the database schema to get column names.
        for (i, column_value) in row.column_values.iter().enumerate() {
            let key = format!("col_{}", i); // Use column index as key
            let php_value = self.convert_column_value_to_php(column_value)?;
            map.insert(key, php_value);
        }
        
        let mut obj_zval = Zval::new();
        obj_zval.set_array(map)?;
        
        // Convert to stdClass object
        let stdclass_ce = zend::ClassEntry::try_find("stdClass")
            .ok_or_else(|| PhpException::default("stdClass not found".into()))?;
        
        let mut obj = ext_php_rs::types::ZendObject::new(stdclass_ce);
        for (i, column_value) in row.column_values.iter().enumerate() {
            let prop_name = format!("col_{}", i);
            let prop_zval = self.convert_column_value_to_php(column_value)?;
            obj.set_property(&prop_name, prop_zval)?;
        }
        
        let mut result = Zval::new();
        result.set_object(&mut *obj.into_raw());
        Ok(result)
    }
    
    fn convert_column_value_to_php(&self, column_value: &ColumnValue) -> PhpResult<Zval> {
        let mut zval = Zval::new();
        
        match column_value {
            ColumnValue::None => {
                zval.set_null();
            },
            ColumnValue::Tiny(i) => zval.set_long(*i as i64),
            ColumnValue::Short(i) => zval.set_long(*i as i64),
            ColumnValue::Long(i) => zval.set_long(*i as i64),
            ColumnValue::LongLong(i) => zval.set_long(*i),
            ColumnValue::Float(f) => zval.set_double(*f as f64),
            ColumnValue::Double(d) => zval.set_double(*d),
            ColumnValue::Decimal(d) => zval.set_string(d, false)?,
            ColumnValue::Date(date) => zval.set_string(date, false)?,
            ColumnValue::DateTime(dt) => zval.set_string(dt, false)?,
            ColumnValue::Time(t) => zval.set_string(t, false)?,
            ColumnValue::Timestamp(ts) => zval.set_long(*ts),
            ColumnValue::Year(y) => zval.set_long(*y as i64),
            ColumnValue::String(bytes) => {
                // Convert Vec<u8> to string, assuming UTF-8
                if let Ok(s) = String::from_utf8(bytes.clone()) {
                    zval.set_string(&s, false)?;
                } else {
                    // Fall back to base64 encoding for non-UTF-8 data
                    use base64::Engine;
                    let engine = base64::engine::general_purpose::STANDARD;
                    let encoded = engine.encode(bytes);
                    zval.set_string(&encoded, false)?;
                }
            },
            ColumnValue::Blob(bytes) => {
                // Encode binary data as base64
                use base64::Engine;
                let engine = base64::engine::general_purpose::STANDARD;
                let encoded = engine.encode(bytes);
                zval.set_string(&encoded, false)?;
            },
            ColumnValue::Json(bytes) => {
                // Try to parse as JSON string
                if let Ok(json_str) = mysql_binlog_connector_rust::column::json::json_binary::JsonBinary::parse_as_string(bytes) {
                    zval.set_string(&json_str, false)?;
                } else {
                    // Fall back to base64 encoding
                    use base64::Engine;
                    let engine = base64::engine::general_purpose::STANDARD;
                    let encoded = engine.encode(bytes);
                    zval.set_string(&encoded, false)?;
                }
            },
            ColumnValue::Bit(value) => {
                zval.set_long(*value as i64);
            },
            ColumnValue::Set(value) => {
                zval.set_long(*value as i64);
            },
            ColumnValue::Enum(value) => {
                zval.set_long(*value as i64);
            }
        }
        
        Ok(zval)
    }
    
    
    fn create_event(
        &self, 
        class_name: &str, 
        event_type: &str, 
        timestamp: i32, 
        checkpoint: &str, 
        schema: &str, 
        table: &str, 
        before_data: Option<Zval>, 
        after_data: Option<Zval>
    ) -> PhpResult<Option<Zval>> {
        // Find the event class
        let ce = zend::ClassEntry::try_find(class_name)
            .ok_or_else(|| PhpException::default(format!("Class {} not found", class_name).into()))?;
        
        // Create new object instance
        let obj = ext_php_rs::types::ZendObject::new(ce);
        
        // Prepare constructor parameters
        let timestamp_i64 = timestamp as i64;
        let mut params: Vec<&dyn ext_php_rs::convert::IntoZvalDyn> = vec![
            &event_type,
            &timestamp_i64,
            &checkpoint,
            &schema,
            &table,
        ];
        
        // Add objects to params in the correct order
        if let Some(ref before) = before_data {
            params.push(before);
        }
        if let Some(ref after) = after_data {
            params.push(after);
        }
        
        // Call constructor
        let _result = obj.try_call_method("__construct", params)?;
        
        // Convert object to Zval
        let mut event_zval = Zval::new();
        event_zval.set_object(&mut *obj.into_raw());
        Ok(Some(event_zval))
    }
}

impl StreamDriver for MySQLStreamDriver {
    fn connect(&mut self) -> PhpResult<()> {
        if self.connected {
            return Ok(());
        }

        // Create a Tokio runtime for async operations
        let rt = tokio::runtime::Runtime::new()
            .map_err(|e| PhpException::default(format!("Failed to create Tokio runtime: {}", e).into()))?;

        rt.block_on(async {
            // Build MySQL connection options
            // For replication, we don't connect to a specific database
            // The replication user needs REPLICATION SLAVE/CLIENT privileges, not database access
            let opts = OptsBuilder::default()
                .ip_or_hostname(&self.host)
                .tcp_port(self.port)
                .user(Some(&self.user))
                .pass(Some(&self.password));

            // Create connection pool
            let pool = Pool::new(opts);

            // Validate MySQL configuration
            self.validate_mysql_config(&pool).await
                .map_err(|e| PhpException::default(format!("MySQL configuration invalid: {}", e).into()))?;

            // Get current GTID position for binlog streaming
            let current_gtid = self.get_current_gtid(&pool).await
                .map_err(|e| PhpException::default(format!("Failed to get GTID: {}", e).into()))?;
            
            self.current_gtid = Some(current_gtid);
            self.pool = Some(pool);
            self.connected = true;

            Ok(())
        })
    }

    fn disconnect(&mut self) -> PhpResult<()> {
        if !self.connected {
            return Ok(());
        }

        self.pool = None;
        self.binlog_client = None;
        self.binlog_stream = None;
        self.current_gtid = None;
        self.current_event = None;
        self.event_iterator_started = false;
        self.connected = false;
        self.table_map.clear();
        
        Ok(())
    }

    fn set_checkpointer(&mut self, _checkpointer: &Zval) -> PhpResult<()> {
        Ok(())
    }

    fn set_filter(&mut self, _filter: &Zval) -> PhpResult<()> {
        Ok(())
    }

    fn current(&self) -> PhpResult<Option<Zval>> {
        if !self.connected || !self.event_iterator_started {
            return Ok(None);
        }
        
        // Return the current event if available
        if let Some(ref event) = self.current_event {
            // Create a new Zval and copy the content
            let mut result = Zval::new();
            unsafe {
                // Copy the zval content - this is a shallow copy
                std::ptr::copy_nonoverlapping(event, &mut result, 1);
            }
            Ok(Some(result))
        } else {
            Ok(None)
        }
    }

    fn key(&self) -> PhpResult<i32> {
        Ok(self.position as i32)
    }

    fn next(&mut self) -> PhpResult<()> {
        if !self.connected || !self.event_iterator_started {
            return Err(PhpException::default("Stream not connected or not started".into()).into());
        }
        
        self.position += 1;
        self.fetch_next_event()?;
        Ok(())
    }

    fn rewind(&mut self) -> PhpResult<()> {
        // Establish connection if not connected (as per spec)
        if !self.connected {
            self.connect()?;
        }
        
        // Initialize binlog client with current GTID (async)
        let rt = tokio::runtime::Runtime::new()
            .map_err(|e| PhpException::default(format!("Failed to create Tokio runtime: {}", e).into()))?;
        
        rt.block_on(async {
            self.initialize_binlog_client().await
        })?;
        
        self.position = 0;
        self.event_iterator_started = true;
        
        // Fetch the first event
        self.fetch_next_event()?;
        
        Ok(())
    }

    fn valid(&self) -> PhpResult<bool> {
        Ok(self.connected && self.event_iterator_started && self.current_event.is_some())
    }
}