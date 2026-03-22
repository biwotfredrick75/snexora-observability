<?php

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Base Repository
 * 
 * Abstract repository providing common CRUD operations.
 * All repositories should extend this class.
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * The model instance.
     */
    protected Model $model;

    /**
     * Create a new repository instance.
     */
    public function __construct()
    {
        $this->model = $this->getModel();
    }

    /**
     * Get the model instance.
     */
    abstract protected function getModel(): Model;

    /**
     * Get all records.
     */
    public function all(array $columns = ['*']): mixed
    {
        return $this->model->select($columns)->get();
    }

    /**
     * Find a record by ID.
     */
    public function find(int $id, array $columns = ['*']): mixed
    {
        return $this->model->select($columns)->find($id);
    }

    /**
     * Create a new record.
     */
    public function create(array $data): mixed
    {
        return $this->model->create($data);
    }

    /**
     * Update a record.
     */
    public function update(int $id, array $data): mixed
    {
        $model = $this->find($id);

        if (!$model) {
            return null;
        }

        $model->update($data);
        return $model;
    }

    /**
     * Delete a record.
     */
    public function delete(int $id): bool
    {
        $model = $this->find($id);

        if (!$model) {
            return false;
        }

        return (bool) $model->delete();
    }

    /**
     * Find by attribute.
     */
    public function findBy(string $attribute, mixed $value, array $columns = ['*']): mixed
    {
        return $this->model->select($columns)->where($attribute, $value)->first();
    }

    /**
     * Get paginated records.
     */
    public function paginate(int $perPage = 15, array $columns = ['*']): mixed
    {
        return $this->model->select($columns)->paginate($perPage);
    }
}
