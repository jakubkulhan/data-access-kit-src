use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use crate::StreamDriver;

#[derive(Debug)]
pub struct MySQLStreamDriver {
    host: String,
    port: u16,
    user: String,
    password: String,
    database: Option<String>,
    server_id: Option<u32>,
    position: u64,
}

impl MySQLStreamDriver {
    pub fn new(
        host: String,
        port: u16,
        user: String,
        password: String,
        database: Option<String>,
        server_id: Option<u32>,
    ) -> Self {
        MySQLStreamDriver {
            host,
            port,
            user,
            password,
            database,
            server_id,
            position: 0,
        }
    }
}

impl StreamDriver for MySQLStreamDriver {
    fn connect(&mut self) -> PhpResult<()> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    fn disconnect(&mut self) -> PhpResult<()> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    fn set_checkpointer(&mut self, _checkpointer: &Zval) -> PhpResult<()> {
        Ok(())
    }

    fn set_filter(&mut self, _filter: &Zval) -> PhpResult<()> {
        Ok(())
    }

    fn current(&self) -> PhpResult<Option<Zval>> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    fn key(&self) -> PhpResult<i32> {
        Ok(self.position as i32)
    }

    fn next(&mut self) -> PhpResult<()> {
        self.position += 1;
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    fn rewind(&mut self) -> PhpResult<()> {
        self.position = 0;
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }

    fn valid(&self) -> PhpResult<bool> {
        Err(PhpException::default("TODO: will be implemented".into()).into())
    }
}