<?php

namespace App\Repositories\Auth;

use App\Models\User;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * User Repository
 * 
 * Handles all database operations related to users.
 */
class UserRepository extends BaseRepository
{
    /**
     * Get the model instance.
     */
    protected function getModel(): Model
    {
        return new User();
    }

    /**
     * Find user by email.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->findBy('email', $email);
    }

    /**
     * Get users by role.
     */
    public function getUsersByRole(string $roleName)
    {
        return User::role($roleName)->get();
    }

    /**
     * Get active users (inactive=0 means active in this schema).
     */
    public function getActiveUsers(array $columns = ['*'])
    {
        return $this->model->select($columns)
            ->where('inactive', false)
            ->get();
    }

    /**
     * Deactivate user.
     */
    public function deactivate(int $id): bool
    {
        return $this->update($id, ['inactive' => true]) !== null;
    }

    /**
     * Activate user.
     */
    public function activate(int $id): bool
    {
        return $this->update($id, ['inactive' => false]) !== null;
    }

    /**
     * Get user with roles and permissions.
     */
    public function getUserWithPermissions(int $id)
    {
        return User::with(['roles.permissions'])
            ->find($id);
    }
}
