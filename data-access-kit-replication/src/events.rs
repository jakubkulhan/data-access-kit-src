use ext_php_rs::prelude::*;
use ext_php_rs::ffi;
use ext_php_rs::types::Zval;
use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::zend::ClassEntry;
use ext_php_rs::flags::{ClassFlags, DataType};
use ext_php_rs::error::Result;
use std::ffi::CString;
use std::{mem, ptr};

// Global pointer to EventInterface
static mut EVENT_INTERFACE: *mut ClassEntry = ptr::null_mut();

// Function to get EventInterface CE
pub fn event_interface_ce() -> &'static ClassEntry {
    unsafe {
        EVENT_INTERFACE.as_ref().expect("EventInterface not initialized")
    }
}

// Unsafe function to register EventInterface
pub unsafe fn register_event_interface() {
    // Create and register EventInterface
    let mut interface_ce: ffi::zend_class_entry = mem::zeroed();

    // Set the interface name
    let name = CString::new("DataAccessKit\\Replication\\EventInterface").unwrap();
    interface_ce.name = ffi::ext_php_rs_zend_string_init(
        name.as_ptr(),
        name.as_bytes().len(),
        true
    );

    // Set interface flags
    interface_ce.ce_flags = ClassFlags::Interface.bits();

    // Register the interface
    let registered = ffi::zend_register_internal_class_ex(
        &mut interface_ce as *mut _,
        ptr::null_mut(),
    );

    if registered.is_null() {
        eprintln!("Failed to register EventInterface");
        ffi::ext_php_rs_zend_string_release(interface_ce.name);
        return;
    }

    // Store the EventInterface reference globally
    EVENT_INTERFACE = registered;

    // Add constants to the interface
    let insert_const = CString::new("INSERT").unwrap();
    let mut insert_val = Zval::new();
    insert_val.set_string("INSERT", true).unwrap();
    ffi::zend_declare_class_constant(
        registered,
        insert_const.as_ptr(),
        insert_const.as_bytes().len(),
        Box::into_raw(Box::new(insert_val)),
    );

    let update_const = CString::new("UPDATE").unwrap();
    let mut update_val = Zval::new();
    update_val.set_string("UPDATE", true).unwrap();
    ffi::zend_declare_class_constant(
        registered,
        update_const.as_ptr(),
        update_const.as_bytes().len(),
        Box::into_raw(Box::new(update_val)),
    );

    let delete_const = CString::new("DELETE").unwrap();
    let mut delete_val = Zval::new();
    delete_val.set_string("DELETE", true).unwrap();
    ffi::zend_declare_class_constant(
        registered,
        delete_const.as_ptr(),
        delete_const.as_bytes().len(),
        Box::into_raw(Box::new(delete_val)),
    );
}

// Wrapper for Zval that implements Clone
pub struct Mixed(Zval);

impl Clone for Mixed {
    fn clone(&self) -> Self {
        Mixed(self.0.shallow_clone())
    }
}

impl Mixed {
    pub fn new(val: &Zval) -> Self {
        Mixed(val.shallow_clone())
    }
}

impl IntoZval for Mixed {
    const TYPE: DataType = DataType::Mixed;
    const NULLABLE: bool = true;

    fn set_zval(self, zv: &mut Zval, _persistent: bool) -> Result<()> {
        *zv = self.0;
        Ok(())
    }
}

impl<'a> FromZval<'a> for Mixed {
    const TYPE: DataType = DataType::Mixed;

    fn from_zval(zval: &'a Zval) -> Option<Self> {
        Some(Mixed(zval.shallow_clone()))
    }
}

#[php_class]
#[php(name = "DataAccessKit\\Replication\\InsertEvent")]
#[php(implements(ce = event_interface_ce, stub = "DataAccessKit\\Replication\\EventInterface"))]
pub struct InsertEvent {
    #[php(prop, name = "type")]
    r#type: String,
    #[php(prop)]
    timestamp: i64,
    #[php(prop)]
    checkpoint: String,
    #[php(prop)]
    schema: String,
    #[php(prop)]
    table: String,
    #[php(prop)]
    after: Mixed,
}

#[php_impl]
impl InsertEvent {
    pub fn __construct(
        r#type: String,
        timestamp: i64,
        checkpoint: String,
        schema: String,
        table: String,
        after: &Zval,
    ) -> PhpResult<Self> {
        Ok(InsertEvent {
            r#type,
            timestamp,
            checkpoint,
            schema,
            table,
            after: Mixed::new(after),
        })
    }
}

#[php_class]
#[php(name = "DataAccessKit\\Replication\\UpdateEvent")]
#[php(implements(ce = event_interface_ce, stub = "DataAccessKit\\Replication\\EventInterface"))]
pub struct UpdateEvent {
    #[php(prop, name = "type")]
    r#type: String,
    #[php(prop)]
    timestamp: i64,
    #[php(prop)]
    checkpoint: String,
    #[php(prop)]
    schema: String,
    #[php(prop)]
    table: String,
    #[php(prop)]
    before: Mixed,
    #[php(prop)]
    after: Mixed,
}

#[php_impl]
impl UpdateEvent {
    pub fn __construct(
        r#type: String,
        timestamp: i64,
        checkpoint: String,
        schema: String,
        table: String,
        before: &Zval,
        after: &Zval,
    ) -> PhpResult<Self> {
        Ok(UpdateEvent {
            r#type,
            timestamp,
            checkpoint,
            schema,
            table,
            before: Mixed::new(before),
            after: Mixed::new(after),
        })
    }
}

#[php_class]
#[php(name = "DataAccessKit\\Replication\\DeleteEvent")]
#[php(implements(ce = event_interface_ce, stub = "DataAccessKit\\Replication\\EventInterface"))]
pub struct DeleteEvent {
    #[php(prop, name = "type")]
    r#type: String,
    #[php(prop)]
    timestamp: i64,
    #[php(prop)]
    checkpoint: String,
    #[php(prop)]
    schema: String,
    #[php(prop)]
    table: String,
    #[php(prop)]
    before: Mixed,
}

#[php_impl]
impl DeleteEvent {
    pub fn __construct(
        r#type: String,
        timestamp: i64,
        checkpoint: String,
        schema: String,
        table: String,
        before: &Zval,
    ) -> PhpResult<Self> {
        Ok(DeleteEvent {
            r#type,
            timestamp,
            checkpoint,
            schema,
            table,
            before: Mixed::new(before),
        })
    }
}