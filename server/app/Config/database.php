<?php

require_once __DIR__ . '/../Core/Database.php';

return Database::connect($_ENV);