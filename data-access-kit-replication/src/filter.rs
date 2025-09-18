use ext_php_rs::ffi;
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ZendType};
use std::ffi::CString;
use std::{mem, ptr};

// Global pointer to StreamFilterInterface
static mut FILTER_INTERFACE: *mut ClassEntry = ptr::null_mut();

// Unsafe function to register StreamFilterInterface
pub unsafe fn register_filter_interface() {
    // Create and register StreamFilterInterface
    let mut interface_ce: ffi::zend_class_entry = mem::zeroed();

    // Set the interface name
    let name = CString::new("DataAccessKit\\Replication\\StreamFilterInterface").unwrap();
    interface_ce.name =
        ffi::ext_php_rs_zend_string_init(name.as_ptr(), name.as_bytes().len(), true);

    // Set interface flags
    interface_ce.ce_flags = ClassFlags::Interface.bits();

    // Create function entries for the interface methods
    let mut functions: Vec<ffi::zend_function_entry> = Vec::new();

    // Create arginfo for accept method
    let mut arg_infos: Vec<ffi::zend_internal_arg_info> = Vec::new();

    // First element: metadata (return type bool, 3 required args)
    arg_infos.push(ffi::zend_internal_arg_info {
        name: 3 as *const _, // required_num_args
        type_: ZendType::empty_from_type(DataType::Bool, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    // Argument 1: $type (string)
    let type_arg_name = CString::new("type").unwrap();
    arg_infos.push(ffi::zend_internal_arg_info {
        name: type_arg_name.into_raw(),
        type_: ZendType::empty_from_type(DataType::String, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    // Argument 2: $schema (string)
    let schema_arg_name = CString::new("schema").unwrap();
    arg_infos.push(ffi::zend_internal_arg_info {
        name: schema_arg_name.into_raw(),
        type_: ZendType::empty_from_type(DataType::String, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    // Argument 3: $table (string)
    let table_arg_name = CString::new("table").unwrap();
    arg_infos.push(ffi::zend_internal_arg_info {
        name: table_arg_name.into_raw(),
        type_: ZendType::empty_from_type(DataType::String, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    // Create the accept method
    let accept_name = CString::new("accept").unwrap();
    let num_args = (arg_infos.len() - 1) as u32; // Subtract 1 for the metadata entry
    let arg_info_ptr = Box::into_raw(arg_infos.into_boxed_slice()) as *const _;

    let accept_method = ffi::zend_function_entry {
        fname: accept_name.as_ptr(),
        handler: None,
        arg_info: arg_info_ptr,
        num_args,
        flags: (ffi::ZEND_ACC_PUBLIC | ffi::ZEND_ACC_ABSTRACT) as u32,
        doc_comment: ptr::null(),
        frameless_function_infos: ptr::null(),
    };
    functions.push(accept_method);

    // Add terminating entry
    functions.push(ffi::zend_function_entry {
        fname: ptr::null(),
        handler: None,
        arg_info: ptr::null(),
        num_args: 0,
        flags: 0,
        doc_comment: ptr::null(),
        frameless_function_infos: ptr::null(),
    });

    // Set the functions on the interface
    interface_ce.info.internal.builtin_functions = functions.as_ptr();

    // Register the interface
    let registered =
        ffi::zend_register_internal_class_ex(&mut interface_ce as *mut _, ptr::null_mut());

    // Prevent the vectors and strings from being dropped
    mem::forget(functions);
    mem::forget(accept_name);
    mem::forget(name);

    if registered.is_null() {
        eprintln!("Failed to register StreamFilterInterface");
        return;
    }

    // Store the interface reference globally
    FILTER_INTERFACE = registered;
}

/// Rust wrapper for PHP StreamFilterInterface
/// Provides a clean abstraction over PHP filter objects
#[derive(Debug)]
pub struct Filter {
    php_object: Zval,
}

impl Filter {
    /// Create a new filter wrapper from a PHP object
    pub fn new(php_filter: &Zval) -> PhpResult<Self> {
        // Validate that the object implements the required interface
        if !php_filter.is_object() {
            return Err(PhpException::default(
                "Filter must be an object implementing StreamFilterInterface".into(),
            )
            .into());
        }

        // Use shallow_clone to safely store the Zval reference
        Ok(Filter {
            php_object: php_filter.shallow_clone(),
        })
    }

    /// Call the accept method on the PHP filter object
    /// Returns true if the event should be accepted, false if it should be filtered out
    pub fn accept(&self, event_type: &str, schema: &str, table: &str) -> PhpResult<bool> {
        // Call the accept(string $type, string $schema, string $table) method on the PHP object
        let params: Vec<&dyn ext_php_rs::convert::IntoZvalDyn> = vec![&event_type, &schema, &table];
        let result = self.php_object.try_call_method("accept", params)?;

        if result.is_bool() {
            Ok(result.bool().unwrap_or(false))
        } else {
            Err(PhpException::default("accept() method must return boolean".into()).into())
        }
    }
}
