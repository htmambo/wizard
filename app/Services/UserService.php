<?php

namespace App\Services\User;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {}
    
    public function getAllUsers(int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage);
    }
    
    public function createUser(array $data): User
    {
        return $this->userRepository->create($data);
    }
    
    public function updateUser(User $user, array $data): User
    {
        return $this->userRepository->update($user, $data);
    }
}
