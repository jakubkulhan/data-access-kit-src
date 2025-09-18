use ext_php_rs::prelude::*;
use ext_php_rs::ffi;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::{ClassEntry, ZendType};
use ext_php_rs::flags::{ClassFlags, DataType};
use std::ffi::CString;
use std::{mem, ptr};

// Global pointer to StreamCheckpointerInterface
static mut CHECKPOINTER_INTERFACE: *mut ClassEntry = ptr::null_mut();

// Unsafe function to register StreamCheckpointerInterface
pub unsafe fn register_checkpointer_interface() {
    // Create and register StreamCheckpointerInterface
    let mut interface_ce: ffi::zend_class_entry = mem::zeroed();

    // Set the interface name
    let name = CString::new("DataAccessKit\\Replication\\StreamCheckpointerInterface").unwrap();
    interface_ce.name = ffi::ext_php_rs_zend_string_init(
        name.as_ptr(),
        name.as_bytes().len(),
        true
    );

    // Set interface flags
    interface_ce.ce_flags = ClassFlags::Interface.bits();

    // Create function entries for the interface methods
    let mut functions: Vec<ffi::zend_function_entry> = Vec::new();

    // Create arginfo for loadLastCheckpoint method (no parameters, returns ?string)
    let mut load_arg_infos: Vec<ffi::zend_internal_arg_info> = Vec::new();

    // First element: metadata (return type ?string, 0 required args)
    load_arg_infos.push(ffi::zend_internal_arg_info {
        name: 0 as *const _, // required_num_args
        type_: ZendType::empty_from_type(DataType::String, false, false, true) // nullable string
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    let load_method_name = CString::new("loadLastCheckpoint").unwrap();
    let load_num_args = (load_arg_infos.len() - 1) as u32;
    let load_arg_info_ptr = Box::into_raw(load_arg_infos.into_boxed_slice()) as *const _;

    let load_method = ffi::zend_function_entry {
        fname: load_method_name.as_ptr(),
        handler: None,
        arg_info: load_arg_info_ptr,
        num_args: load_num_args,
        flags: (ffi::ZEND_ACC_PUBLIC | ffi::ZEND_ACC_ABSTRACT) as u32,
        doc_comment: ptr::null(),
        frameless_function_infos: ptr::null(),
    };
    functions.push(load_method);

    // Create arginfo for saveCheckpoint method (1 string parameter, returns void)
    let mut save_arg_infos: Vec<ffi::zend_internal_arg_info> = Vec::new();

    // First element: metadata (return type void, 1 required arg)
    save_arg_infos.push(ffi::zend_internal_arg_info {
        name: 1 as *const _, // required_num_args
        type_: ZendType::empty_from_type(DataType::Void, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    // Argument 1: $checkpoint (string)
    let checkpoint_arg_name = CString::new("checkpoint").unwrap();
    save_arg_infos.push(ffi::zend_internal_arg_info {
        name: checkpoint_arg_name.into_raw(),
        type_: ZendType::empty_from_type(DataType::String, false, false, false)
            .unwrap_or_else(|| ZendType::empty(false, false)),
        default_value: ptr::null(),
    });

    let save_method_name = CString::new("saveCheckpoint").unwrap();
    let save_num_args = (save_arg_infos.len() - 1) as u32;
    let save_arg_info_ptr = Box::into_raw(save_arg_infos.into_boxed_slice()) as *const _;

    let save_method = ffi::zend_function_entry {
        fname: save_method_name.as_ptr(),
        handler: None,
        arg_info: save_arg_info_ptr,
        num_args: save_num_args,
        flags: (ffi::ZEND_ACC_PUBLIC | ffi::ZEND_ACC_ABSTRACT) as u32,
        doc_comment: ptr::null(),
        frameless_function_infos: ptr::null(),
    };
    functions.push(save_method);

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
    let registered = ffi::zend_register_internal_class_ex(
        &mut interface_ce as *mut _,
        ptr::null_mut(),
    );

    // Prevent the vectors and strings from being dropped
    mem::forget(functions);
    mem::forget(load_method_name);
    mem::forget(save_method_name);
    mem::forget(name);

    if registered.is_null() {
        eprintln!("Failed to register StreamCheckpointerInterface");
        return;
    }

    // Store the interface reference globally
    CHECKPOINTER_INTERFACE = registered;
}

/// Rust wrapper for PHP StreamCheckpointerInterface
/// Provides a clean abstraction over PHP checkpointer objects
#[derive(Debug)]
pub struct Checkpointer {
    php_object: Zval,
}

impl Checkpointer {
    /// Create a new checkpointer wrapper from a PHP object
    pub fn new(php_checkpointer: &Zval) -> PhpResult<Self> {
        // Validate that the object implements the required interface
        if !php_checkpointer.is_object() {
            return Err(PhpException::default(
                "Checkpointer must be an object implementing StreamCheckpointerInterface".into()
            ).into());
        }

        // Use shallow_clone to safely store the Zval reference
        Ok(Checkpointer {
            php_object: php_checkpointer.shallow_clone(),
        })
    }

    /// Load the last checkpoint from the PHP checkpointer
    /// Returns None if no checkpoint exists or if the method returns null
    pub fn load_last_checkpoint(&self) -> PhpResult<Option<String>> {
        // Call the loadLastCheckpoint() method on the PHP object
        let result = self.php_object.try_call_method("loadLastCheckpoint", Vec::<&dyn ext_php_rs::convert::IntoZvalDyn>::new())?;

        if result.is_null() {
            Ok(None)
        } else if result.is_string() {
            Ok(Some(result.string().unwrap_or_default().to_string()))
        } else {
            Err(PhpException::default(
                "loadLastCheckpoint() must return string or null".into()
            ).into())
        }
    }

    /// Save a checkpoint using the PHP checkpointer
    pub fn save_checkpoint(&self, checkpoint: &str) -> PhpResult<()> {
        // Call the saveCheckpoint(string $checkpoint) method on the PHP object
        let params: Vec<&dyn ext_php_rs::convert::IntoZvalDyn> = vec![&checkpoint];
        let _result = self.php_object.try_call_method("saveCheckpoint", params)?;

        Ok(())
    }
}

