use ext_php_rs::prelude::*;
use ext_php_rs::ffi;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::ce;
use std::sync::Once;

static INTERFACES_INIT: Once = Once::new();

#[php_class]
#[php(name = "DataAccessKit\\Replication\\Stream")]
#[php(implements(ce = ce::iterator, stub = "Iterator"))]
#[derive(Debug, Clone)]
pub struct Stream {
    connection_url: String,
    connected: bool,
    position: u64,
}

#[php_impl]
impl Stream {
    pub fn __construct(connection_url: String) -> PhpResult<Self> {
        Ok(Stream {
            connection_url,
            connected: false,
            position: 0,
        })
    }

    pub fn connect(&mut self) -> PhpResult<()> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    pub fn disconnect(&mut self) -> PhpResult<()> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    pub fn set_checkpointer(&mut self, _checkpointer: &Zval) -> PhpResult<()> {
        Ok(())
    }

    pub fn set_filter(&mut self, _filter: &Zval) -> PhpResult<()> {
        Ok(())
    }

    // Iterator interface methods
    pub fn current(&self) -> PhpResult<Option<Zval>> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    pub fn key(&self) -> PhpResult<i32> {
        Ok(self.position as i32)
    }

    pub fn next(&mut self) -> PhpResult<()> {
        self.position += 1;
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    pub fn rewind(&mut self) -> PhpResult<()> {
        self.position = 0;
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    pub fn valid(&self) -> PhpResult<bool> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }
}

unsafe extern "C" fn startup_function(_type: i32, _module_number: i32) -> i32 {
    // Module startup - just return success, actual loading happens in request startup
    0 // SUCCESS
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
        .startup_function(startup_function)
        .request_startup_function(request_startup_function)
}
