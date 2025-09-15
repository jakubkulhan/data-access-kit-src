use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;

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
                "Filter must be an object implementing StreamFilterInterface".into()
            ).into());
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
            Err(PhpException::default(
                "accept() method must return boolean".into()
            ).into())
        }
    }
}