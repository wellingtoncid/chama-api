# AGENTS.md - Chama Frete API

## Overview

This is a REST API built with PHP 8.1+ using a custom MVC-like architecture with:
- **Controllers** (`src/Controllers/`) - Request handling
- **Repositories** (`src/Repositories/`) - Data access layer
- **Services** (`src/Services/`) - Business logic
- **Core** (`src/Core/`) - Database, Router, Response, Auth

## Build & Runtime Commands

### Installation
```bash
composer install
```

### Running the Server
```bash
# Using PHP built-in server (from project root)
php -S localhost:8000 -t public
```

### PHP Syntax Check
```bash
# Check a single file
php -l src/Controllers/AuthController.php

# Check all PHP files recursively
find src -name "*.php" -exec php -l {} \;
```

### Running with Xdebug (VS Code)
1. Install Xdebug extension for VS Code
2. Configure `launch.json` with path mappings to `/mnt/c/xampp/htdocs/chama-frete/api`

### Database
- MySQL/MariaDB on port 3306 (configurable via `.env`)
- Uses PDO with prepared statements
- Schema includes: `users`, `accounts`, `user_profiles`, `freights`, `reviews`, `notifications`, etc.

## Testing

**No formal test framework is currently configured.**

To add tests in the future:
```bash
composer require --dev phpunit/phpunit
```

Manual testing can be done via:
- Postman/Insomnia collections
- curl commands
- Frontend application

## Code Style Guidelines

### General Rules
- PHP 8.1+ syntax only (typed properties, match expressions, named arguments)
- Use `<?php` opening tag (no short tags `<?`)
- Always use strict types: `declare(strict_types=1);` at top of new files
- Use `JSON_UNESCAPED_UNICODE` when encoding JSON responses

### Naming Conventions
| Element | Convention | Example |
|---------|------------|---------|
| Classes | PascalCase | `AuthController`, `UserRepository` |
| Methods | camelCase | `getProfile()`, `updatePassword()` |
| Variables | camelCase | `$loggedUser`, `$userId` |
| Constants | UPPER_SNAKE_CASE | `JWT_SECRET`, `MAX_RETRY` |
| Files | PascalCase.php | `AuthController.php` |
| Database tables | snake_case | `user_profiles`, `reset_tokens` |
| Database columns | snake_case | `account_id`, `created_at` |

### Namespaces
- Follow PSR-4: `App\Controllers`, `App\Repositories`, `App\Services`, `App\Core`
- One class per file
- File path must match namespace

```php
<?php
namespace App\Controllers;

class UserController {
    // ...
}
```

### Imports
- Use `use` statements at top of file
- Group vendor imports separately from App imports
- Order: Native PHP → Vendor → App

```php
<?php
namespace App\Controllers;

use PDO;
use Exception;

use App\Core\Response;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
```

### Class Structure
```php
<?php
namespace App\Controllers;

use App\Core\Response;
use App\Repositories\UserRepository;
use Exception;

class AuthController {
    // Properties with types
    private UserRepository $userRepo;
    private string $secret;

    // Constructor with dependency injection
    public function __construct($db) {
        $this->userRepo = new UserRepository($db);
        $this->secret = $_ENV['JWT_SECRET'] ?? 'fallback_secret';
    }

    // Public methods (controllers)
    public function login($data) {
        // Implementation
    }

    // Private helper methods
    private function sendResetEmail($to, $token): bool {
        // Implementation
    }
}
```

### SQL & Database
- **ALWAYS use prepared statements** with named parameters
- Never concatenate user input into SQL strings
- Use `FETCH_ASSOC` for consistent array results

```php
// Good
$stmt = $this->db->prepare("SELECT id, name FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Bad - SQL Injection risk!
$stmt = $this->db->query("SELECT * FROM users WHERE id = $userId");
```

### Error Handling
- Use try-catch blocks for operations that may fail
- Log errors with `error_log()` including context
- Return user-friendly messages; log detailed errors server-side
- Always return JSON responses via `Response::json()`

```php
try {
    $userId = $this->userRepo->create($preparedData);
    return Response::json(["success" => true, "userId" => $userId], 201);
} catch (Exception $e) {
    error_log("Erro ao criar usuário: " . $e->getMessage());
    
    if (strpos($e->getMessage(), '1062') !== false) {
        return Response::json(["success" => false, "message" => "Duplicate entry"], 409);
    }
    
    return Response::json(["success" => false, "message" => "Internal error"], 500);
}
```

### Response Format
All API responses should follow this structure:
```php
// Success
Response::json([
    "success" => true,
    "data" => $result
], 200);

// Error
Response::json([
    "success" => false,
    "message" => "Human-readable error message"
], 400);
```

### Input Validation
- Sanitize all inputs: `trim()`, `filter_var()`, `preg_replace('/\D/', '', ...)`
- Validate required fields before processing
- Use appropriate HTTP status codes:
  - `200` - Success
  - `201` - Created
  - `400` - Bad Request
  - `401` - Unauthorized
  - `403` - Forbidden
  - `404` - Not Found
  - `409` - Conflict
  - `500` - Server Error

### Security Guidelines
- Never expose sensitive data in responses
- Use `password_hash()` and `password_verify()` for passwords
- Validate JWT tokens on protected routes
- Check user roles/permissions for admin operations
- Use environment variables (`.env`) for secrets - never commit them

### Comments
- Use docblocks for public API methods
- Keep comments in Portuguese (project language) or English consistently
- Avoid obvious comments; code should be self-documenting

### Code Organization in Controllers
1. Property declarations
2. Constructor
3. Public methods (endpoints)
4. Private helper methods

### File Headers
Each new PHP file should have:
```php
<?php
namespace App\Controllers;

// imports...

class ControllerName {
    // class implementation
}
```

### Routes
Routes are defined in `public/index.php` using the Router class:
```php
$router->post('/api/login', 'AuthController@login');
$router->get('/api/user/:id', 'UserController@getUser');
```

### Environment Configuration
Use `.env` file for configuration:
```
DB_HOST=127.0.0.1
DB_NAME=chama_frete_dev
DB_USER=root
DB_PASS=
DB_PORT=3306
JWT_SECRET=your_secret
JWT_EXPIRE=86400
SMTP_HOST=smtp.example.com
SMTP_PORT=587
APP_DEBUG=false
```

## Common Patterns

### Repository Pattern
```php
class UserRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
}
```

### Transaction Handling
```php
public function createUserWithAccount($data) {
    try {
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
        
        // ... operations ...
        
        $this->db->commit();
        return $userId;
    } catch (Exception $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        throw $e;
    }
}
```

### Soft Deletes
Use `deleted_at` column with timestamp (not hard deletes):
```php
$stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id AND deleted_at IS NULL");
```

## Roles & Permissions

### Database Tables
- `roles` - User roles (admin, driver, company, etc.)
- `permissions` - Individual permissions (freight.create, marketplace.view, etc.)
- `role_permissions` - Many-to-many relationship between roles and permissions
- `modules` - System modules (fretes, marketplace, chat, grupos, etc.)
- `user_modules` - Active modules per user

### Available Roles
**External (platform users):**
- `driver` - Motorista
- `company` - Empresa/Transportadora

**Internal (Chama Frete team):**
- `admin` - Administrador Master
- `gerente` - Gerente
- `suporte` - Suporte
- `financeiro` - Financeiro
- `marketing` - Marketing
- `vendas` - Vendas
- `coordenador` - Coordenador
- `supervisor` - Supervisor

### Available Modules
- `fretes` - Fretes (obrigatório para company/driver)
- `marketplace` - Anúncios de vendas
- `cotacoes` - Sistema de cotações
- `publicidade` - Anúncios publicitários
- `chat` - Mensagens
- `financeiro` - Transações e relatórios
- `grupos` - Grupos WhatsApp
- `planos` - Planos de assinatura
- `suporte` - Tickets de suporte

### Auth Helper Methods
```php
use App\Core\Auth;

// Check user role
Auth::hasRole('admin');

// Check any of multiple roles
Auth::hasAnyRole(['admin', 'manager']);

// Check specific permission
Auth::hasPermission('freight.create');

// Check module access
Auth::hasModule('marketplace');

// Require auth or role
$user = Auth::requireAuth();
$user = Auth::requireRole('admin');
$user = Auth::requirePermission('freight.create');
```

### API Endpoints
```
# Roles
GET    /api/admin-roles         - List all roles
POST   /api/admin-roles         - Create role
PUT    /api/admin-roles         - Update role
DELETE /api/admin-roles         - Delete role

# Permissions
GET    /api/admin-permissions   - List all permissions
POST   /api/admin-permissions   - Create permission
PUT    /api/admin-permissions   - Update permission
DELETE /api/admin-permissions   - Delete permission

# Modules
GET    /api/admin-modules      - List all modules
POST   /api/admin-modules       - Create module
PUT    /api/admin-modules      - Update module
DELETE /api/admin-modules      - Delete module
```
