use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use ext_php_rs::zend;
use crate::{StreamDriver, Checkpointer, Filter};
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
use tokio::runtime::Runtime;

macro_rules! with_runtime_block_on {
    ($self:ident, $async_block:expr) => {{
        $self.ensure_runtime()?;
        let runtime = $self.runtime.take().unwrap();
        let result = runtime.block_on($async_block);
        $self.runtime = Some(runtime);
        result
    }};
}


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
    server_id: Option<u32>,
    position: u64,
    pool: Option<Pool>,
    binlog_client: Option<BinlogClient>,
    binlog_stream: Option<BinlogStream>,
    current_gtid: Option<String>,
    current_binlog_file: Option<String>,
    current_binlog_position: Option<u64>,
    is_mariadb: bool,
    use_gtid_checkpoints: bool,
    current_event: Option<Zval>,
    event_iterator_started: bool,
    connected: bool,
    table_map: std::collections::HashMap<u64, TableMapEvent>,
    checkpointer: Option<Checkpointer>,
    filter: Option<Filter>,
    runtime: Option<Runtime>,
}


impl MySQLStreamDriver {

    pub fn new(
        host: String,
        port: u16,
        user: String,
        password: String,
        server_id: Option<u32>,
    ) -> Self {
        MySQLStreamDriver {
            host: host.clone(),
            port,
            user: user.clone(),
            password,
            server_id,
            position: 0,
            pool: None,
            binlog_client: None,
            binlog_stream: None,
            current_gtid: None,
            current_binlog_file: None,
            current_binlog_position: None,
            is_mariadb: false,
            use_gtid_checkpoints: false,
            current_event: None,
            event_iterator_started: false,
            connected: false,
            table_map: std::collections::HashMap::new(),
            checkpointer: None,
            filter: None,
            runtime: None,
        }
    }

    fn ensure_runtime(&mut self) -> PhpResult<()> {
        if self.runtime.is_none() {
            let rt = tokio::runtime::Builder::new_current_thread()
                .enable_all()
                .build()
                .map_err(|e| PhpException::default(format!("Failed to create Tokio runtime: {}", e).into()))?;
            self.runtime = Some(rt);
        }
        Ok(())
    }


    async fn validate_mysql_config(&mut self, pool: &Pool) -> Result<(), String> {
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
        
        // Detect database type by checking version
        let version: String = mysql_async::prelude::Queryable::query_first(
            &mut conn,
            "SELECT VERSION()"
        ).await
            .map_err(|e| format!("Failed to query database version: {}", e))?
            .unwrap_or_default();

        self.is_mariadb = version.to_lowercase().contains("mariadb");

        if !self.is_mariadb {
            // MySQL - check GTID configuration
            let gtid_mode: String = mysql_async::prelude::Queryable::query_first(
                &mut conn,
                "SHOW VARIABLES LIKE 'gtid_mode'"
            ).await
                .map_err(|e| format!("Failed to query gtid_mode: {}", e))?
                .map(|row: (String, String)| row.1)
                .unwrap_or_default();

            if gtid_mode.to_uppercase() != "ON" {
                return Err(format!("gtid_mode must be ON, got: {}", gtid_mode));
            }

            // MySQL with GTID enabled - use GTID checkpointing
            self.use_gtid_checkpoints = true;
        } else {
            // MariaDB - always use binlog file/position checkpointing (per spec)
            self.use_gtid_checkpoints = false;
        }
        
        Ok(())
    }
    
    async fn get_current_gtid(&self, pool: &Pool) -> Result<String, String> {
        let mut conn = pool.get_conn().await
            .map_err(|e| format!("Failed to get connection for GTID: {}", e))?;

        // Use appropriate GTID variable based on database type
        let gtid_query = if self.is_mariadb {
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

    async fn get_current_binlog_position(&mut self, pool: &Pool) -> Result<(String, u64), String> {
        let mut conn = pool.get_conn().await
            .map_err(|e| format!("Failed to get connection for binlog position: {}", e))?;

        // Get current binlog file and position using SHOW MASTER STATUS
        // Handle both MySQL and MariaDB by extracting columns by name from the row
        use mysql_async::prelude::*;

        let query = if self.is_mariadb {
            "SHOW MASTER STATUS"
        } else {
            // MySQL 8.0+ uses SHOW BINARY LOG STATUS instead of SHOW MASTER STATUS
            "SHOW BINARY LOG STATUS"
        };

        let result: Option<mysql_async::Row> = conn.query_first(query).await
            .map_err(|e| format!("Failed to query master status: {}", e))?;

        match result {
            Some(row) => {
                // Extract File and Position columns manually from the row
                let file: String = row.get("File")
                    .ok_or_else(|| "Missing File column in SHOW MASTER STATUS".to_string())?;

                // Handle position - try different types since MySQL/MariaDB might return different types
                let position = if let Some(pos_u64) = row.get::<u64, _>("Position") {
                    // Position returned as u64 (MySQL)
                    pos_u64
                } else if let Some(pos_str) = row.get::<String, _>("Position") {
                    // Position returned as string (MariaDB or other cases)
                    pos_str.parse::<u64>()
                        .map_err(|e| format!("Failed to parse binlog position '{}': {}", pos_str, e))?
                } else {
                    return Err("Missing or invalid Position column in SHOW MASTER STATUS".to_string());
                };

                self.current_binlog_file = Some(file.clone());
                self.current_binlog_position = Some(position);
                Ok((file, position))
            }
            None => Err("No master status available - is binary logging enabled?".to_string())
        }
    }

    fn generate_checkpoint(&self, header: &EventHeader) -> String {
        if self.use_gtid_checkpoints && !self.is_mariadb {
            // MySQL with GTID - use "gtid:" prefix
            if let Some(ref gtid) = self.current_gtid {
                format!("gtid:{}", gtid)
            } else {
                // Fallback to file/position if GTID not available
                self.generate_file_position_checkpoint(header)
            }
        } else {
            // MariaDB or MySQL without GTID - use "file:" prefix
            self.generate_file_position_checkpoint(header)
        }
    }

    fn generate_file_position_checkpoint(&self, header: &EventHeader) -> String {
        if let Some(ref binlog_client) = self.binlog_client {
            // Use the current binlog file and position from the client
            format!("file:{}:{}", binlog_client.binlog_filename, header.next_event_position)
        } else if let (Some(ref file), Some(_pos)) = (&self.current_binlog_file, &self.current_binlog_position) {
            // Use stored file and position from header
            format!("file:{}:{}", file, header.next_event_position)
        } else {
            // Emergency fallback - use position from header
            format!("file:binlog.{:06}:{}", header.next_event_position / 1_000_000, header.next_event_position)
        }
    }

    /// Save the current checkpoint using the configured checkpointer
    fn save_current_checkpoint(&self, header: &EventHeader) -> PhpResult<()> {
        if let Some(ref checkpointer) = self.checkpointer {
            let checkpoint = self.generate_checkpoint(header);
            checkpointer.save_checkpoint(&checkpoint)?;
        }
        // If no checkpointer is configured, silently continue
        Ok(())
    }

    /// Load checkpoint from checkpointer if available and apply it
    fn load_checkpoint_if_available(&mut self) -> PhpResult<()> {
        if let Some(ref checkpointer) = self.checkpointer {
            if let Some(checkpoint_str) = checkpointer.load_last_checkpoint()? {
                self.apply_checkpoint(&checkpoint_str)?;
            }
        }
        Ok(())
    }

    /// Parse and apply a checkpoint string to set the starting position
    fn apply_checkpoint(&mut self, checkpoint: &str) -> PhpResult<()> {
        if checkpoint.starts_with("gtid:") {
            // GTID checkpoint format: "gtid:3E11FA47-71CA-11E1-9E33-C80AA9429562:23"
            let gtid_str = &checkpoint[5..]; // Remove "gtid:" prefix
            self.current_gtid = Some(gtid_str.to_string());

            // When using GTID, we don't need specific binlog file/position
            self.current_binlog_file = None;
            self.current_binlog_position = None;

        } else if checkpoint.starts_with("file:") {
            // File/position checkpoint format: "file:mysql-bin.000123:45678"
            let file_pos_str = &checkpoint[5..]; // Remove "file:" prefix

            if let Some(colon_pos) = file_pos_str.rfind(':') {
                let filename = &file_pos_str[..colon_pos];
                let position_str = &file_pos_str[colon_pos + 1..];

                match position_str.parse::<u64>() {
                    Ok(position) => {
                        self.current_binlog_file = Some(filename.to_string());
                        self.current_binlog_position = Some(position);

                        // Clear GTID when using file/position
                        self.current_gtid = None;
                    }
                    Err(e) => {
                        return Err(PhpException::default(
                            format!("Invalid binlog position in checkpoint '{}': {}", checkpoint, e).into()
                        ).into());
                    }
                }
            } else {
                return Err(PhpException::default(
                    format!("Invalid file checkpoint format: '{}'", checkpoint).into()
                ).into());
            }
        } else {
            return Err(PhpException::default(
                format!("Invalid checkpoint format: '{}'. Must start with 'gtid:' or 'file:'", checkpoint).into()
            ).into());
        }

        Ok(())
    }


    async fn initialize_binlog_client(&mut self) -> PhpResult<()> {
        let connection_url = format!("mysql://{}:{}@{}:{}",
            self.user, self.password, self.host, self.port);

        let mut binlog_client = if !self.is_mariadb && self.use_gtid_checkpoints {
            // MySQL with GTID - use GTID mode
            let gtid_set = self.current_gtid.clone().unwrap_or_default();
            BinlogClient {
                url: connection_url,
                binlog_filename: "".to_string(),
                binlog_position: 4,
                server_id: self.server_id.unwrap_or_else(|| {
                    NEXT_SERVER_ID.fetch_add(1, Ordering::Relaxed)
                }) as u64,
                gtid_enabled: true,
                gtid_set,
                heartbeat_interval_secs: 30,
                timeout_secs: 60,
            }
        } else {
            // MariaDB (always) or MySQL without GTID - use binlog file/position
            let binlog_file = self.current_binlog_file.clone().unwrap_or_else(|| {
                String::new()
            });
            let binlog_position = self.current_binlog_position.unwrap_or(4);

            BinlogClient {
                url: connection_url,
                binlog_filename: binlog_file,
                binlog_position: binlog_position as u32,
                server_id: self.server_id.unwrap_or_else(|| {
                    NEXT_SERVER_ID.fetch_add(1, Ordering::Relaxed)
                }) as u64,
                gtid_enabled: false,
                gtid_set: String::new(), // Explicitly empty for MariaDB
                heartbeat_interval_secs: 30,
                timeout_secs: 60,
            }
        };

        // Connect to binlog stream
        let binlog_stream = binlog_client.connect().await
            .map_err(|e| PhpException::default(format!("Failed to connect to binlog: {}", e).into()))?;

        self.binlog_stream = Some(binlog_stream);
        self.binlog_client = Some(binlog_client);
        Ok(())
    }
    
    fn fetch_next_event(&mut self) -> PhpResult<()> {
        with_runtime_block_on!(self, async {
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
                                // Check filter before processing
                                if let Some(ref filter) = self.filter {
                                    match filter.accept("INSERT", &table_map.database_name, &table_map.table_name) {
                                        Ok(false) => {
                                            // Event is filtered out, skip it and continue to next event
                                            continue;
                                        }
                                        Ok(true) => {
                                            // Event is accepted, continue processing
                                        }
                                        Err(e) => {
                                            // Filter error - log and skip this event
                                            eprintln!("Filter error: {:?}", e);
                                            continue;
                                        }
                                    }
                                }

                                // Convert to InsertEvent
                                for row in &write_rows_event.rows {
                                    match self.create_insert_event_from_binlog(
                                        &header,
                                        table_map,
                                        row
                                    ) {
                                        Ok(event_obj) => {
                                            self.current_event = Some(event_obj);
                                            // Save checkpoint after successfully creating event
                                            self.save_current_checkpoint(&header)?;
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
                                // Check filter before processing
                                if let Some(ref filter) = self.filter {
                                    match filter.accept("UPDATE", &table_map.database_name, &table_map.table_name) {
                                        Ok(false) => {
                                            // Event is filtered out, skip it and continue to next event
                                            continue;
                                        }
                                        Ok(true) => {
                                            // Event is accepted, continue processing
                                        }
                                        Err(e) => {
                                            // Filter error - log and skip this event
                                            eprintln!("Filter error: {:?}", e);
                                            continue;
                                        }
                                    }
                                }

                                // Convert to UpdateEvent
                                for (before_row, after_row) in &update_rows_event.rows {
                                    let event_obj = self.create_update_event_from_binlog(
                                        &header,
                                        table_map,
                                        before_row,
                                        after_row
                                    )?;
                                    self.current_event = Some(event_obj);
                                    // Save checkpoint after successfully creating event
                                    self.save_current_checkpoint(&header)?;
                                    return Ok(());
                                }
                            }
                            // Skip if no table map found
                            continue;
                        },

                        EventData::DeleteRows(delete_rows_event) => {
                            if let Some(table_map) = self.table_map.get(&delete_rows_event.table_id) {
                                // Check filter before processing
                                if let Some(ref filter) = self.filter {
                                    match filter.accept("DELETE", &table_map.database_name, &table_map.table_name) {
                                        Ok(false) => {
                                            // Event is filtered out, skip it and continue to next event
                                            continue;
                                        }
                                        Ok(true) => {
                                            // Event is accepted, continue processing
                                        }
                                        Err(e) => {
                                            // Filter error - log and skip this event
                                            eprintln!("Filter error: {:?}", e);
                                            continue;
                                        }
                                    }
                                }

                                // Convert to DeleteEvent
                                for row in &delete_rows_event.rows {
                                    let event_obj = self.create_delete_event_from_binlog(
                                        &header,
                                        table_map,
                                        row
                                    )?;
                                    self.current_event = Some(event_obj);
                                    // Save checkpoint after successfully creating event
                                    self.save_current_checkpoint(&header)?;
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
        let checkpoint = self.generate_checkpoint(header);
        
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
        let checkpoint = self.generate_checkpoint(header);
        
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
        let checkpoint = self.generate_checkpoint(header);
        
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
    
    fn create_data_object_from_row(&self, table_map: &TableMapEvent, row: &RowEvent) -> PhpResult<Zval> {
        // Convert to stdClass object with proper column names
        let stdclass_ce = zend::ClassEntry::try_find("stdClass")
            .ok_or_else(|| PhpException::default("stdClass not found".into()))?;

        let mut obj = ext_php_rs::types::ZendObject::new(stdclass_ce);

        for (i, column_value) in row.column_values.iter().enumerate() {
            // Get column name from table metadata - error if unavailable
            let column_name = if let Some(ref table_metadata) = table_map.table_metadata {
                if let Some(column_metadata) = table_metadata.columns.get(i) {
                    if let Some(ref name) = column_metadata.column_name {
                        name.clone()
                    } else {
                        return Err(PhpException::default(
                            format!("Column name not available for column index {} in table {}.{}",
                                i, table_map.database_name, table_map.table_name).into()
                        ).into());
                    }
                } else {
                    return Err(PhpException::default(
                        format!("Column metadata not available for column index {} in table {}.{}",
                            i, table_map.database_name, table_map.table_name).into()
                    ).into());
                }
            } else {
                return Err(PhpException::default(
                    format!("Table metadata not available for table {}.{} - ensure binlog_row_metadata=FULL",
                        table_map.database_name, table_map.table_name).into()
                ).into());
            };

            let prop_zval = self.convert_column_value_to_php(column_value)?;
            obj.set_property(&column_name, prop_zval)?;
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

        with_runtime_block_on!(self, async {
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

            // Validate MySQL configuration (this also sets is_mariadb and use_gtid_checkpoints)
            self.validate_mysql_config(&pool).await
                .map_err(|e| PhpException::default(format!("MySQL configuration invalid: {}", e).into()))?;

            // Get current GTID position for binlog streaming (only for MySQL with GTID)
            // Only set if not already set by checkpoint
            if self.use_gtid_checkpoints && !self.is_mariadb && self.current_gtid.is_none() {
                let current_gtid = self.get_current_gtid(&pool).await
                    .map_err(|e| PhpException::default(format!("Failed to get GTID: {}", e).into()))?;
                self.current_gtid = Some(current_gtid);
            }

            // Always get binlog file/position for checkpointing if not set by checkpoint
            if self.current_binlog_file.is_none() || self.current_binlog_position.is_none() {
                let (binlog_file, binlog_position) = self.get_current_binlog_position(&pool).await
                    .map_err(|e| PhpException::default(format!("Failed to get binlog position: {}", e).into()))?;

                // Store for checkpoint generation only if not already set
                if self.current_binlog_file.is_none() {
                    self.current_binlog_file = Some(binlog_file);
                }
                if self.current_binlog_position.is_none() {
                    self.current_binlog_position = Some(binlog_position);
                }
            }
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
        self.current_binlog_file = None;
        self.current_binlog_position = None;
        self.is_mariadb = false;
        self.use_gtid_checkpoints = false;
        self.current_event = None;
        self.event_iterator_started = false;
        self.connected = false;
        self.table_map.clear();
        self.checkpointer = None;
        self.filter = None;
        self.runtime = None;
        
        Ok(())
    }

    fn set_checkpointer(&mut self, checkpointer: &Zval) -> PhpResult<()> {
        let wrapper = if checkpointer.is_null() {
            None
        } else {
            Some(Checkpointer::new(checkpointer)?)
        };

        self.checkpointer = wrapper;
        Ok(())
    }

    fn set_filter(&mut self, filter: &Zval) -> PhpResult<()> {
        let wrapper = if filter.is_null() {
            None
        } else {
            Some(Filter::new(filter)?)
        };

        self.filter = wrapper;
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
            // No current event - this is normal at the start or when no events are available
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

        // Load checkpoint BEFORE creating BinlogClient so it uses the checkpoint position
        // instead of the current database position
        self.load_checkpoint_if_available()?;

        // Initialize binlog client with checkpoint position (async)
        with_runtime_block_on!(self, async {
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