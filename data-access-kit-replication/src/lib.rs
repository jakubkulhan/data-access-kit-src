use ext_php_rs::prelude::*;

mod stream;
mod checkpointer;
mod filter;
mod events;

use stream::Stream;
use checkpointer::Checkpointer;
use filter::Filter;
use events::{InsertEvent, UpdateEvent, DeleteEvent};


fn startup_function(_type: i32, _module_number: i32) -> i32 {
    unsafe {
        // Register EventInterface and its constants
        events::register_event_interface();

        // Register StreamCheckpointerInterface
        checkpointer::register_checkpointer_interface();

        // Register StreamFilterInterface
        filter::register_filter_interface();
    }
    0 // SUCCESS
}

#[php_module]
#[php(startup = startup_function)]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<Stream>()
        .class::<InsertEvent>()
        .class::<UpdateEvent>()
        .class::<DeleteEvent>()
}
