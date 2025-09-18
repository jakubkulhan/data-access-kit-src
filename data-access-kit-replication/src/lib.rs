use ext_php_rs::prelude::*;

mod checkpointer;
mod events;
mod filter;
mod stream;

use checkpointer::Checkpointer;
use events::{DeleteEvent, InsertEvent, UpdateEvent};
use filter::Filter;
use stream::Stream;

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
