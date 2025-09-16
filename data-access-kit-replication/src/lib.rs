use ext_php_rs::prelude::*;
use ext_php_rs::ffi;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::ce;
use std::sync::Once;
use url::Url;

mod mysql;
mod checkpointer;
mod filter;

use mysql::MySQLStreamDriver;
use checkpointer::Checkpointer;
use filter::Filter;

static INTERFACES_INIT: Once = Once::new();

trait StreamDriver {
    fn connect(&mut self) -> PhpResult<()>;
    fn disconnect(&mut self) -> PhpResult<()>;
    fn set_checkpointer(&mut self, checkpointer: &Zval) -> PhpResult<()>;
    fn set_filter(&mut self, filter: &Zval) -> PhpResult<()>;
    fn current(&self) -> PhpResult<Option<Zval>>;
    fn key(&self) -> PhpResult<i32>;
    fn next(&mut self) -> PhpResult<()>;
    fn rewind(&mut self) -> PhpResult<()>;
    fn valid(&self) -> PhpResult<bool>;
}

#[php_class]
#[php(name = "DataAccessKit\\Replication\\Stream")]
#[php(implements(ce = ce::iterator, stub = "Iterator"))]
pub struct Stream {
    driver: Box<dyn StreamDriver>,
}

impl Stream {
    fn create_driver(connection_url: &str) -> Result<Box<dyn StreamDriver>, PhpException> {
        match Url::parse(connection_url) {
            Ok(url) => {
                match url.scheme() {
                    "mysql" => {
                        let host = url.host_str()
                            .unwrap_or("localhost")
                            .to_string();
                        
                        let port = url.port().unwrap_or(3306);
                        
                        let user = if url.username().is_empty() {
                            "root".to_string()
                        } else {
                            url.username().to_string()
                        };
                        
                        let password = url.password()
                            .unwrap_or("")
                            .to_string();

                        let server_id = url.query_pairs()
                            .find(|(key, _)| key == "server_id")
                            .and_then(|(_, value)| value.parse::<u32>().ok());
                        
                        Ok(Box::new(MySQLStreamDriver::new(
                            host,
                            port,
                            user,
                            password,
                            server_id,
                        )))
                    },
                    scheme => Err(PhpException::default(format!("Unsupported protocol: {}", scheme).into())),
                }
            },
            Err(e) => Err(PhpException::default(format!("Invalid connection URL: {}", e).into())),
        }
    }
}

#[php_impl]
impl Stream {
    pub fn __construct(connection_url: String) -> PhpResult<Self> {
        let driver = Self::create_driver(&connection_url)?;
        Ok(Stream {
            driver,
        })
    }

    pub fn connect(&mut self) -> PhpResult<()> {
        self.driver.connect()
    }

    pub fn disconnect(&mut self) -> PhpResult<()> {
        self.driver.disconnect()
    }

    pub fn set_checkpointer(&mut self, checkpointer: &Zval) -> PhpResult<()> {
        self.driver.set_checkpointer(checkpointer)
    }

    pub fn set_filter(&mut self, filter: &Zval) -> PhpResult<()> {
        self.driver.set_filter(filter)
    }

    // Iterator interface methods
    pub fn current(&self) -> PhpResult<Option<Zval>> {
        self.driver.current()
    }

    pub fn key(&self) -> PhpResult<i32> {
        self.driver.key()
    }

    pub fn next(&mut self) -> PhpResult<()> {
        self.driver.next()
    }

    pub fn rewind(&mut self) -> PhpResult<()> {
        self.driver.rewind()
    }

    pub fn valid(&self) -> PhpResult<bool> {
        self.driver.valid()
    }
}


unsafe extern "C" fn request_startup_function(_type: i32, _module_number: i32) -> i32 {
    // Use Once to ensure interfaces are only loaded once per process
    INTERFACES_INIT.call_once(|| {
        let interface_code = include_str!("lib.php");
        
        // Prepend ?> to properly handle the <?php opening tag when eval'ing
        let eval_code = format!("?>{}", interface_code);
        
        let code_cstr = match std::ffi::CString::new(eval_code) {
            Ok(cstr) => cstr,
            Err(_) => {
                eprintln!("Failed to create CString from interface code");
                return;
            }
        };
        
        let filename_cstr = match std::ffi::CString::new("lib.php") {
            Ok(cstr) => cstr,
            Err(_) => {
                eprintln!("Failed to create filename CString");
                return;
            }
        };
        
        // Use the FFI to call zend_eval_string when PHP is ready
        let result = ffi::zend_eval_string(
            code_cstr.as_ptr(),
            std::ptr::null_mut(), // No return value needed
            filename_cstr.as_ptr(),
        );
        
        // Check if evaluation was successful
        if result != 0 {
            eprintln!("Failed to evaluate interface code during request startup");
        }
    });
    
    0 // SUCCESS
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<Stream>()
        .request_startup_function(request_startup_function)
}
