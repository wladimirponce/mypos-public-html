<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Clase base para todos los repositorios.
 */
abstract class BaseRepository
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }
}
