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