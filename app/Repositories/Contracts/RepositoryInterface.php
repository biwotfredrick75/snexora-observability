<?php

namespace App\Repositories\Contracts;

/**
 * Base Repository Interface
 * 
 * Defines the contract for all repositories to ensure consistency
 * and enable easy swapping of implementations.
 */
interface RepositoryInterface
{
    /**
     * Get all records.
     */
    public function all(array $columns = ['*']): mixed;

    /**
     * Find a record by ID.
     */
    public function find(int $id, array $columns = ['*']): mixed;

    /**
     * Create a new record.
     */
    public function create(array $data): mixed;

    /**
     * Update a record.
     */
    public function update(int $id, array $data): mixed;

    /**
     * Delete a record.
     */
    public function delete(int $id): bool;

    /**
     * Find by attribute.
     */
    public function findBy(string $attribute, mixed $value, array $columns = ['*']): mixed;

    /**
     * Get paginated records.
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): mixed;
}
