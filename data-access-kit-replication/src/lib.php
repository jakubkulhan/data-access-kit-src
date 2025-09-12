<?php

namespace DataAccessKit\Replication;

interface StreamCheckpointerInterface {
    public function loadLastCheckpoint(): ?string;
    public function saveCheckpoint(string $checkpoint): void;
}

interface StreamFilterInterface {
    public function accept(string $type, string $schema, string $table): bool;
}

interface EventInterface {
    public const string INSERT = 'INSERT';
    public const string UPDATE = 'UPDATE';
    public const string DELETE = 'DELETE';
    
    public string $type { get; }
    public int $timestamp { get; }
    public string $checkpoint { get; }
    public string $schema { get; }
    public string $table { get; }
}

final readonly class InsertEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $after
    ) {}
}

final readonly class UpdateEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $before,
        public object $after
    ) {}
}

final readonly class DeleteEvent implements EventInterface {
    public function __construct(
        public string $type,
        public int $timestamp,
        public string $checkpoint,
        public string $schema,
        public string $table,
        public object $before
    ) {}
}