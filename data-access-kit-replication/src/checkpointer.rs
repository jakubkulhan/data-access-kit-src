use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;

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

