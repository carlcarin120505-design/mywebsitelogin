<?php
declare(strict_types=1);
session_start();

/* Database connection. Prefer MySQL when available, but fall back to SQLite so the app still runs in local demos. */
$dbHost = getenv('COMMUTERPASS_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('COMMUTERPASS_DB_NAME') ?: 'commuterpass';
$dbUser = getenv('COMMUTERPASS_DB_USER') ?: 'root';
$dbPassword = getenv('COMMUTERPASS_DB_PASSWORD') ?: '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
    exit('Invalid database name configuration.');
}
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$useSqliteFallback = false;
try {
    $pdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPassword, $pdoOptions);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_accounts (
            id VARCHAR(20) PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            school VARCHAR(190) NOT NULL,
            school_student_number VARCHAR(50) NULL,
            school_year VARCHAR(9) NOT NULL DEFAULT '',
            semester VARCHAR(10) NOT NULL DEFAULT '',
            password VARCHAR(255) NOT NULL,
            eligible TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATE NOT NULL,
            failed_scan_day DATE NULL,
            failed_scan_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transactions (
            id VARCHAR(40) PRIMARY KEY,
            student_id VARCHAR(20) NOT NULL DEFAULT '',
            student_name VARCHAR(150) NOT NULL,
            passenger_type VARCHAR(30) NOT NULL,
            destination VARCHAR(255) NOT NULL,
            regular_fare DECIMAL(10,2) NOT NULL,
            discount DECIMAL(10,2) NOT NULL,
            paid_fare DECIMAL(10,2) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_transactions_created_at (created_at)
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fare_matrix (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fare_month CHAR(7) NOT NULL,
            origin VARCHAR(150) NOT NULL,
            destination VARCHAR(150) NOT NULL,
            route_key VARCHAR(255) NOT NULL,
            distance_km DECIMAL(8,2) NOT NULL,
            fare DECIMAL(10,2) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fare_matrix_route_month (fare_month, route_key),
            INDEX idx_fare_matrix_month (fare_month)
        ) ENGINE=InnoDB
    ");
} catch (PDOException $exception) {
    $sqlitePath = __DIR__ . DIRECTORY_SEPARATOR . 'commuterpass.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $sqlitePath, null, null, $pdoOptions);
        $useSqliteFallback = true;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_accounts (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                school TEXT NOT NULL,
                school_student_number TEXT NULL,
                school_year TEXT NOT NULL DEFAULT '',
                semester TEXT NOT NULL DEFAULT '',
                password TEXT NOT NULL,
                eligible INTEGER NOT NULL DEFAULT 1,
                expires_at TEXT NOT NULL,
                failed_scan_day TEXT NULL,
                failed_scan_attempts INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id TEXT PRIMARY KEY,
                student_id TEXT NOT NULL DEFAULT '',
                student_name TEXT NOT NULL,
                passenger_type TEXT NOT NULL,
                destination TEXT NOT NULL,
                regular_fare REAL NOT NULL,
                discount REAL NOT NULL,
                paid_fare REAL NOT NULL,
                created_at DATETIME NOT NULL
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fare_matrix (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fare_month TEXT NOT NULL,
                origin TEXT NOT NULL,
                destination TEXT NOT NULL,
                route_key TEXT NOT NULL,
                distance_km REAL NOT NULL,
                fare REAL NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(fare_month, route_key)
            )
        ");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fare_matrix_month ON fare_matrix (fare_month)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions (created_at)');
    } catch (Throwable $fallbackException) {
        http_response_code(500);
        exit('Database connection failed. Start MySQL in XAMPP and check the database settings.');
    }
}
$academicColumns = [
    'school_student_number' => 'VARCHAR(50) NULL',
    'school_year' => "VARCHAR(9) NOT NULL DEFAULT ''",
    'semester' => "VARCHAR(10) NOT NULL DEFAULT ''",
];
foreach ($academicColumns as $column => $definition) {
    try {
        $pdo->exec("ALTER TABLE student_accounts ADD COLUMN {$column} {$definition}");
    } catch (PDOException $exception) {
        // Existing databases already have this column.
    }
}
if ($useSqliteFallback) {
    $pdo->exec("UPDATE student_accounts SET school_student_number = NULL WHERE TRIM(school_student_number) = ''");
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_student_school_number ON student_accounts (school, school_student_number)');
} else {
    $pdo->exec('ALTER TABLE student_accounts MODIFY school_student_number VARCHAR(50) NULL');
    $pdo->exec("UPDATE student_accounts SET school_student_number = NULL WHERE TRIM(school_student_number) = ''");
    $indexCheck = $pdo->prepare('SELECT 1 FROM information_schema.statistics
        WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1');
    $indexCheck->execute([$dbName, 'student_accounts', 'uq_student_school_number']);
    if (!$indexCheck->fetchColumn()) {
        $pdo->exec('CREATE UNIQUE INDEX uq_student_school_number ON student_accounts (school, school_student_number)');
    }
}
$legacyStudentFile = __DIR__ . DIRECTORY_SEPARATOR . 'student_accounts.json';
$legacyTransactionFile = __DIR__ . DIRECTORY_SEPARATOR . 'transactions.json';
if ((int) $pdo->query('SELECT COUNT(*) FROM student_accounts')->fetchColumn() === 0 && is_file($legacyStudentFile)) {
    $legacyAccounts = json_decode((string) file_get_contents($legacyStudentFile), true);
    $migrateAccount = $pdo->prepare('INSERT OR IGNORE INTO student_accounts
        (id, name, email, school, password, eligible, expires_at, failed_scan_day, failed_scan_attempts)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (is_array($legacyAccounts) ? $legacyAccounts : [] as $account) {
        if (isset($account['id'], $account['name'], $account['email'], $account['school'], $account['password'], $account['expires_at'])) {
            $migrateAccount->execute([
                $account['id'], $account['name'], $account['email'], $account['school'], $account['password'],
                (int) ($account['eligible'] ?? true), $account['expires_at'], $account['failed_scan_day'] ?? null,
                (int) ($account['failed_scan_attempts'] ?? 0),
            ]);
        }
    }
}
$studentAccounts = $pdo->query('SELECT * FROM student_accounts ORDER BY created_at ASC')->fetchAll();
/* Starter fare table: replace these sample fares with the approved fare matrix. */
$calbayogBarangays = [
    'Acedillo', 'Aguit-itan', 'Alibaba', 'Anislag', 'Bagacay', 'Bagong Lipunan',
    'Balud', 'Basud', 'Bontay', 'Buenavista', 'Cacaransan', 'Cagbanayacao',
    'Cagboborac', 'Cagsalaosao', 'Cahumpan', 'Calocnayan', 'Canjumadac', 'Capoocan',
    'Cara', 'Carayman', 'Carmen', 'Central', 'Cogon', 'Dagum', 'Dinawacan',
    'East Awang', 'Esperanza', 'Gabay', 'Gadgaran', 'Gasdo', 'Geragaan', 'Guinbaoyan Norte',
    'Guinbaoyan Sur', 'Guin-on', 'Hamorawon', 'Helino', 'Hibatang', 'Higasaan',
    'Himalandrog', 'Jacinto', 'Jimautan', 'Kalilihan', 'Kilikili', 'La Paz', 'Langoyon',
    'Limarayon', 'Lonoy', 'Looc', 'Mabini I', 'Mancol', 'Matobato', 'Maybog', 'Maysalong',
    'Migara', 'Nag-uma', 'Naga', 'Navarro', 'Nijaga', 'Obrero', 'Olera', 'Osmeña',
    'Pagbalican', 'Palanas', 'Palanogan', 'Panonongon', 'Patong', 'Payahan', 'Pinamorotan',
    'Rawis', 'Rizal I', 'Roxas I', 'Salvacion', 'San Antonio', 'San Jose', 'San Policarpio',
    'Saputan', 'Sinantan', 'Tanval', 'Tapae', 'Tabawan', 'Tigbe', 'Trinidad', 'Victory',
    'Villahermosa', 'West Awang',
];
$staMargaritaBarangays = [
    'Agrupacion', 'Arapison', 'Avelino', 'Bahay', 'Balud', 'Bana-ao', 'Burabod', 'Cagsumje', 'Cautod',
    'Camperito', 'Campeig', 'Can-ipulan', 'Canmoros', 'Cinco', 'Curry', 'Gajo', 'Hindang', 'Ilo', 'Imelda',
    'Inoraguiao', 'Jolacao', 'Lambao', 'Mabuhay', 'Mahayag', 'Matayonas', 'Monbon', 'Nabulo', 'Napuro I',
    'Napuro II', 'Palale', 'Panabatan', 'Panaruan', 'Roxas', 'Salvacion', 'Solsogon', 'Sundara',
    'Cagsumji', 'Napuro 1', 'Napuro 2',
];
$oquendoBarangays = [
    'Baja', 'Bayo', 'Begaho', 'Cabacungan', 'Cabatuan', 'Cabicahan', 'Cabugawan', 'Cag-anahaw', 'Cag-anibong',
    'Cagbayang', 'Cagbilwang', 'Capacuhan', 'Catabunan', 'Caybago', 'Dawo', 'De Victoria', 'Dinabongan', 'Dinagan',
    'Hugon Rosales', 'Jose A. Roño', 'Lapaan', 'Libertad', 'Limarayon', 'Longsob', 'Mabini II', 'Macatingog',
    'Mag-ubay', 'Mantaong', 'Manuel Barral Sr.', 'Mawacat', 'Nabang', 'Obo-ob', 'Oquendo', 'Panlayahan', 'Panoypoy',
    'Pilar', 'Quezon', 'Rizal II', 'Roxas II', 'San Rufino', 'Sigo', 'Sinidman Occidental', 'Sinidman Oriental',
    'Talahiban', 'Tarabucan',
];
$firstLocationPlaces = [
    'Acedillo', 'Aguit-itan', 'Alibaba', 'Amampacang', 'Anislag', 'Awang East', 'Awang West', 'Ba-ay',
    'Bagacay', 'Bagong Lipunan', 'Baja', 'Balud', 'Bante', 'Bantian', 'Basud', 'Bayo', 'Begaho', 'Binaliw',
    'Bontay', 'Buenavista', 'Bugtong', 'Cabacungan', 'Cabatuan', 'Cabicahan', 'Cabugawan', 'Cacaransan',
    'Cag-anahaw', 'Cag-anibong', 'Cagbanayacao', 'Cagbayang', 'Cagbilwang', 'Cagboborac', 'Cagsalaosao',
    'Caglanipao Sur', 'Cagmanipes Norte', 'Cagmanipes Sur', 'Cagnipa', 'Cag-olango', 'Cahumpan', 'Calocnayan',
    'Cangomaod', 'Canhumadac', 'Capacuhan', 'Capoocan', 'Carayman', 'Carmen', 'Catabunan', 'Caybago',
    'Central (Poblacion)', 'Cogon', 'Dagum', 'Danao I', 'Danao II', 'Dawo', 'De Victoria', 'Dinabongan',
    'Dinagan', 'Dinawacan', 'Esperanza', 'Gabay', 'Gadgaran', 'Gasdo', 'Geraga-an', 'Guin-on',
    'Guimbaoyan Norte', 'Guimbaoyan Sur', 'Hamorawon', 'Helino', 'Hibabngan', 'Hibatang', 'Higasaan',
    'Himalandrog', 'Hugon Rosales', 'Jacinto', 'Jimautan', 'Jose A. Roño', 'Kalilihan', 'Kilikili', 'La Paz',
    'Langoyon', 'Lapaan', 'Libertad', 'Limarayon', 'Longsob', 'Lonoy', 'Looc', 'Mabini I', 'Mabini II',
    'Macatingog', 'Mag-ubay', 'Maguino-o', 'Malaga', 'Malajog', 'Malayog', 'Malopalo', 'Mancol', 'Mantaong',
    'Manuel Barral, Sr.', 'Marcatubig', 'Matobato', 'Mawacat', 'Maybog', 'Maysalong', 'Nabang', 'Nag-uma',
    'Naga', 'Navarro', 'Nijaga', 'Obo-ob', 'Obrero', 'Olera', 'Oquendo', 'Osmeña', 'Pagbalican', 'Palanas',
    'Palanogan', 'Panlayahan', 'Panonongan', 'Panoypoy', 'Patong', 'Payahan', 'Peña', 'Pilar', 'Pinamorotan',
    'Quezon', 'Rawis', 'Rizal I', 'Rizal II', 'Roxas I', 'Roxas II', 'Saljag', 'Salvacion', 'San Antonio',
    'San Isidro', 'San Joaquin', 'San Jose', 'San Policarpio', 'San Rufino', 'Saputan', 'Sigo', 'Sinantan',
    'Sinidman Occidental', 'Sinidman Oriental', 'Tabawan', 'Talahiban', 'Tanval', 'Tapa-e', 'Tarabucan',
    'Tigbe', 'Tinambacan Norte', 'Tinambacan Sur', 'Tinaplacan', 'Tomaliguez', 'Trinidad', 'Victory',
    'Villahermosa',
];
$farePerKilometer = 15.00;
$fareForDistance = static function (float $kilometers): float {
    if ($kilometers < 1) {
        return 10.00;
    }
    if ($kilometers <= 1) {
        return 15.00;
    }
    if ($kilometers <= 5) {
        return 30.00;
    }
    return min(45.00, 30.00 + (($kilometers - 5) * 3.00));
};
$destinations = [];
$routeDistances = [];
$destinationPlaces = ['Calbayog City Proper'];
foreach ($staMargaritaBarangays as $barangay) {
    $destinationPlaces[] = 'Sta. Margarita - ' . $barangay;
}
foreach ($oquendoBarangays as $barangay) {
    $destinationPlaces[] = 'Oquendo - ' . $barangay;
}
$destinationPlaces = array_values(array_unique(array_merge($destinationPlaces, $calbayogBarangays)));
$locationDistancesKm = array_fill_keys($firstLocationPlaces, 1.00);
$locationDistancesKm['Calbayog City Proper'] = 0.00;
foreach ($staMargaritaBarangays as $barangay) {
    $locationDistancesKm['Sta. Margarita - ' . $barangay] = 8.00;
}
foreach ($oquendoBarangays as $barangay) {
    $locationDistancesKm['Oquendo - ' . $barangay] = 5.00;
}
foreach ($firstLocationPlaces as $origin) {
    foreach ($destinationPlaces as $target) {
        $route = $origin . ' -> ' . $target;
        $distance = $origin === $target ? 0.50 : max(1.00, abs(
            ($locationDistancesKm[$target] ?? 1.00) - ($locationDistancesKm[$origin] ?? 1.00)
        ));
        $routeDistances[$route] = $distance;
        $destinations[$route] = $fareForDistance($distance);
    }
}
$fareMonth = (string) ($_GET['fare_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fareMonth)) {
    $fareMonth = date('Y-m');
}
$seedFare = $pdo->prepare($useSqliteFallback
    ? 'INSERT OR IGNORE INTO fare_matrix
        (fare_month, origin, destination, route_key, distance_km, fare)
        VALUES (?, ?, ?, ?, ?, ?)'
    : 'INSERT IGNORE INTO fare_matrix
        (fare_month, origin, destination, route_key, distance_km, fare)
        VALUES (?, ?, ?, ?, ?, ?)'
);
if ((int) $pdo->query('SELECT COUNT(*) FROM fare_matrix WHERE fare_month = ' . $pdo->quote($fareMonth))->fetchColumn() === 0) {
    $pdo->beginTransaction();
    try {
        foreach ($routeDistances as $route => $distance) {
            [$origin, $target] = explode(' -> ', $route, 2);
            $seedFare->execute([$fareMonth, $origin, $target, $route, $distance, $destinations[$route]]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
$fareRows = $pdo->prepare('SELECT route_key, distance_km, fare FROM fare_matrix WHERE fare_month = ?');
$fareRows->execute([$fareMonth]);
foreach ($fareRows->fetchAll() as $fareRow) {
    $routeDistances[$fareRow['route_key']] = (float) $fareRow['distance_km'];
    $destinations[$fareRow['route_key']] = (float) $fareRow['fare'];
}
$passengers = [
    'STU-1001' => ['name' => 'Ana Cruz', 'school' => 'Calbayog City College', 'type' => 'Student', 'discount_eligible' => true],
    'STU-1002' => ['name' => 'Ben Santos', 'school' => 'Northwest Samar State University', 'type' => 'Student', 'discount_eligible' => true],
    'STU-1003' => ['name' => 'Carla Reyes', 'school' => 'Calbayog National High School', 'type' => 'Student', 'discount_eligible' => true],
    'REG-2001' => ['name' => 'Daniel Flores', 'school' => 'Regular commuter', 'type' => 'Regular', 'discount_eligible' => false],
    'REG-2002' => ['name' => 'Elena Ramos', 'school' => 'Regular commuter', 'type' => 'Regular', 'discount_eligible' => false],
];

foreach ($studentAccounts as $account) {
    $passengers[$account['id']] = [
        'name' => $account['name'], 'school' => $account['school'], 'type' => 'Student',
        'school_student_number' => strtoupper(trim((string) ($account['school_student_number'] ?? ''))),
        'discount_eligible' => (bool) $account['eligible'], 'expires_at' => $account['expires_at'],
    ];
}
$schoolStudentIdMap = [];
foreach ($studentAccounts as $account) {
    $schoolStudentNumber = strtoupper(trim((string) ($account['school_student_number'] ?? '')));
    if ($schoolStudentNumber === '') {
        continue;
    }
    $schoolStudentIdMap[$schoolStudentNumber] = array_key_exists($schoolStudentNumber, $schoolStudentIdMap)
        ? null
        : $account['id'];
}

$transactionCount = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
if ($transactionCount === 0 && is_file($legacyTransactionFile)) {
    $legacyTransactions = json_decode((string) file_get_contents($legacyTransactionFile), true);
    $migrateTransaction = $pdo->prepare('INSERT OR IGNORE INTO transactions
        (id, student_id, student_name, passenger_type, destination, regular_fare, discount, paid_fare, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach (is_array($legacyTransactions) ? $legacyTransactions : [] as $transaction) {
        if (isset($transaction['id'], $transaction['student_name'], $transaction['destination'], $transaction['created_at'])) {
            $migrateTransaction->execute([
                $transaction['id'], $transaction['student_id'] ?? '', $transaction['student_name'],
                $transaction['passenger_type'] ?? 'Student', $transaction['destination'],
                (float) ($transaction['regular_fare'] ?? 0), (float) ($transaction['discount'] ?? 0),
                (float) ($transaction['paid_fare'] ?? 0), $transaction['created_at'],
            ]);
        }
    }
}
$notice = '';
$noticeType = 'success';
$validSchoolYear = static function (string $schoolYear): bool {
    if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {
        return false;
    }
    return (int) $matches[2] === (int) $matches[1] + 1
        && (int) $matches[1] >= 2000
        && (int) $matches[1] <= (int) date('Y') + 1;
};
$validSemester = static fn (string $semester): bool => in_array($semester, ['1st', '2nd', 'summer'], true);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'save_fare_matrix') {
    $matrixMonth = (string) ($_POST['fare_month'] ?? '');
    $origin = trim((string) ($_POST['origin'] ?? ''));
    $target = trim((string) ($_POST['destination_place'] ?? ''));
    $distance = (float) ($_POST['distance_km'] ?? 0);
    $fare = (float) ($_POST['fare'] ?? 0);
    $routeKey = $origin . ' -> ' . $target;
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $matrixMonth) || !$origin || !$target || $distance < 0 || $fare < 0) {
        $notice = 'Enter a valid month, route, distance, and fare.';
        $noticeType = 'error';
    } else {
        $saveFare = $pdo->prepare($useSqliteFallback
            ? 'INSERT INTO fare_matrix
                (fare_month, origin, destination, route_key, distance_km, fare)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(fare_month, route_key) DO UPDATE SET distance_km = excluded.distance_km, fare = excluded.fare'
            : 'INSERT INTO fare_matrix
                (fare_month, origin, destination, route_key, distance_km, fare)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE distance_km = VALUES(distance_km), fare = VALUES(fare)'
        );
        $saveFare->execute([$matrixMonth, $origin, $target, $routeKey, $distance, $fare]);
        header('Location: ?fare_month=' . rawurlencode($matrixMonth) . '&fare_saved=1');
        exit;
    }
} elseif ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'register_student') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $school = trim((string) ($_POST['school'] ?? ''));
    $schoolStudentNumber = strtoupper(trim((string) ($_POST['school_student_number'] ?? '')));
    $schoolYear = trim((string) ($_POST['school_year'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $existsStatement = $pdo->prepare('SELECT 1 FROM student_accounts WHERE email = ? LIMIT 1');
    $existsStatement->execute([$email]);
    $studentNumberExists = $pdo->prepare('SELECT 1 FROM student_accounts
        WHERE school = ? AND school_student_number = ? LIMIT 1');
    $studentNumberExists->execute([$school, $schoolStudentNumber]);
    if ($name && filter_var($email, FILTER_VALIDATE_EMAIL) && $school
        && preg_match('#^[A-Z0-9][A-Z0-9/-]{0,49}$#', $schoolStudentNumber)
        && $validSchoolYear($schoolYear) && $validSemester($semester)
        && strlen($password) >= 6 && !$existsStatement->fetchColumn() && !$studentNumberExists->fetchColumn()) {
        $account = ['id' => 'STU-' . random_int(100000, 999999), 'name' => $name, 'email' => $email,
            'school' => $school, 'school_student_number' => $schoolStudentNumber,
            'school_year' => $schoolYear, 'semester' => $semester,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'eligible' => true, 'expires_at' => date('Y-m-d', strtotime('+1 year'))];
        $insertAccount = $pdo->prepare('INSERT INTO student_accounts
            (id, name, email, school, school_student_number, school_year, semester, password, eligible, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertAccount->execute([
            $account['id'], $account['name'], $account['email'], $account['school'],
            $account['school_student_number'], $account['school_year'],
            $account['semester'],
            $account['password'], $account['eligible'], $account['expires_at'],
        ]);
        $_SESSION['student_id'] = $account['id'];
        header('Location: ?student_portal=1'); exit;
    }
    $notice = 'Registration failed. Check your email, unique school student ID, school year, semester, and password (at least 6 characters).';
    $noticeType = 'error';
} elseif ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'student_login') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $schoolStudentNumber = strtoupper(trim((string) ($_POST['school_student_number'] ?? '')));
    $schoolYear = trim((string) ($_POST['school_year'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!preg_match('#^[A-Z0-9][A-Z0-9/-]{0,49}$#', $schoolStudentNumber)
        || !$validSchoolYear($schoolYear) || !$validSemester($semester)) {
        $notice = 'Enter a valid school student ID, school year (YYYY-YYYY), and semester.';
        $noticeType = 'error';
    } else {
        foreach ($studentAccounts as $account) {
            if ($account['email'] === $email && password_verify($password, $account['password'])) {
                $storedStudentNumber = strtoupper(trim((string) ($account['school_student_number'] ?? '')));
                if ($storedStudentNumber !== '' && $storedStudentNumber !== $schoolStudentNumber) {
                    $notice = 'School student ID does not match this account.';
                    $noticeType = 'error';
                    break;
                }
                if ($storedStudentNumber === '') {
                    $numberInUse = $pdo->prepare('SELECT 1 FROM student_accounts
                        WHERE school = ? AND school_student_number = ? AND id <> ? LIMIT 1');
                    $numberInUse->execute([$account['school'], $schoolStudentNumber, $account['id']]);
                    if ($numberInUse->fetchColumn()) {
                        $notice = 'That school student ID is already assigned to another account at this school.';
                        $noticeType = 'error';
                        break;
                    }
                }
                if (empty($account['school_year']) || empty($account['semester'])) {
                    $upgradeAccount = $pdo->prepare('UPDATE student_accounts SET school_year = ?, semester = ? WHERE id = ?');
                    $upgradeAccount->execute([$schoolYear, $semester, $account['id']]);
                } elseif ($account['school_year'] !== $schoolYear || $account['semester'] !== $semester) {
                    $notice = 'School year or semester does not match this student account.';
                    $noticeType = 'error';
                    break;
                }
                if ($storedStudentNumber === '') {
                    $updateStudentNumber = $pdo->prepare('UPDATE student_accounts SET school_student_number = ? WHERE id = ?');
                    $updateStudentNumber->execute([$schoolStudentNumber, $account['id']]);
                }
                $_SESSION['student_id'] = $account['id'];
                header('Location: ?student_portal=1'); exit;
            }
        }
        if ($notice === '') {
            $notice = 'Incorrect student email, password, school year, or semester.';
            $noticeType = 'error';
        }
    }
} elseif ($requestMethod === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $destination = (string) ($_POST['destination'] ?? '');
    if (!isset($passengers[$studentId])) {
        $matchingStudentIds = [];
        foreach ($passengers as $passengerId => $passenger) {
            if (!empty($passenger['school_student_number'])
                && hash_equals($passenger['school_student_number'], strtoupper($studentId))) {
                $matchingStudentIds[] = $passengerId;
            }
        }
        if (count($matchingStudentIds) === 1) {
            $studentId = $matchingStudentIds[0];
        }
    }

    if ($studentId !== '' && !isset($passengers[$studentId])) {
        $notice = 'QR code not recognized. Please scan a valid commuter QR code.';
        $noticeType = 'error';
    } elseif (!isset($destinations[$destination])) {
        $notice = 'Please choose a valid passenger destination before scanning.';
        $noticeType = 'error';
    } elseif (!empty($passengers[$studentId]['expires_at']) && $passengers[$studentId]['expires_at'] < date('Y-m-d')) {
        foreach ($studentAccounts as &$account) {
            if ($account['id'] === $studentId) {
                if (($account['failed_scan_day'] ?? '') !== date('Y-m-d')) { $account['failed_scan_day'] = date('Y-m-d'); $account['failed_scan_attempts'] = 0; }
                $account['failed_scan_attempts'] = min(3, (int) ($account['failed_scan_attempts'] ?? 0) + 1);
                $attempt = $account['failed_scan_attempts'];
                break;
            }
        }
        unset($account);
        $updateAttempts = $pdo->prepare('UPDATE student_accounts
            SET failed_scan_day = ?, failed_scan_attempts = ?
            WHERE id = ?');
        $updateAttempts->execute([date('Y-m-d'), $attempt, $studentId]);
        $notice = $attempt >= 3 ? 'Student QR is expired. Maximum 3 retry attempts reached today; renew the student QR first.' : "Student QR is expired. Invalid scan attempt {$attempt} of 3; renew the student QR, then try again.";
        $noticeType = 'error';
    } else {
        $regularFare = (float) $destinations[$destination];
        $passenger = $passengers[$studentId] ?? [
            'name' => 'Regular passenger',
            'type' => 'Regular',
            'discount_eligible' => false,
        ];
        $isStudent = $passenger['discount_eligible'];
        $discount = $isStudent ? round($regularFare * 0.20, 2) : 0.00;
        $paidFare = round($regularFare - $discount, 2);
        $transaction = [
            'id' => 'TX-' . date('Ymd-His') . '-' . random_int(100, 999),
            'student_id' => $studentId,
            'student_name' => $passenger['name'],
            'passenger_type' => $passenger['type'],
            'destination' => $destination,
            'regular_fare' => $regularFare,
            'discount' => $discount,
            'paid_fare' => $paidFare,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $insertTransaction = $pdo->prepare('INSERT INTO transactions
            (id, student_id, student_name, passenger_type, destination, regular_fare, discount, paid_fare, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insertTransaction->execute([
            $transaction['id'], $transaction['student_id'], $transaction['student_name'],
            $transaction['passenger_type'], $transaction['destination'], $transaction['regular_fare'],
            $transaction['discount'], $transaction['paid_fare'], $transaction['created_at'],
        ]);
        $fareType = $isStudent ? 'student 20% discount' : 'regular fare';
        header('Location: ?fare_month=' . rawurlencode($fareMonth) . '&trip_saved=1');
        exit;
    }
}

$transactions = $pdo->query('SELECT * FROM transactions ORDER BY created_at DESC')->fetchAll();
$today = date('Y-m-d');
$todayTransactions = array_values(array_filter($transactions, fn ($t) => strpos((string) ($t['created_at'] ?? ''), $today) === 0));
$totalCollected = array_sum(array_map(fn ($t) => (float) ($t['paid_fare'] ?? 0), $todayTransactions));
$loggedInStudent = null;
foreach ($studentAccounts as $account) {
    if (($account['id'] ?? '') === ($_SESSION['student_id'] ?? '')) { $loggedInStudent = $account; break; }
}
$requestHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost:8000');
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$serverPort = (string) ($_SERVER['SERVER_PORT'] ?? '80');
$lanHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', getenv('COMMUTERPASS_HOST') ?: '');
if ($lanHost === '') {
    $lanHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) ($_SERVER['SERVER_ADDR'] ?? ''));
}
if ($lanHost === '' || in_array($lanHost, ['0.0.0.0', '127.0.0.1', '::', '::1'], true)) {
    $lanHost = preg_replace('/[^a-zA-Z0-9.:-]/', '', gethostbyname(gethostname()));
}
if ($lanHost === '' || in_array($lanHost, ['0.0.0.0', '127.0.0.1', '::', '::1'], true)) {
    $lanHost = 'localhost';
}
if (strpos($lanHost, ':') === false) {
    $lanHost .= ':' . $serverPort;
}
$normalizedHost = strtolower($requestHost);
if ($requestHost === '' || strpos($normalizedHost, 'localhost') === 0 || strpos($normalizedHost, '127.0.0.1') === 0 || strpos($normalizedHost, '0.0.0.0') === 0) {
    $driverHost = $lanHost;
} else {
    $driverHost = $requestHost;
}
$portalPath = strtok($_SERVER['SCRIPT_NAME'] ?? '/index.php', '?');
$publicBaseUrl = trim((string) (getenv('COMMUTERPASS_PUBLIC_URL') ?: ''));
if ($publicBaseUrl !== '') {
    $publicBaseUrl = rtrim($publicBaseUrl, '/');
    if (!filter_var($publicBaseUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?$/i', (string) parse_url($publicBaseUrl, PHP_URL_SCHEME))) {
        exit('Invalid COMMUTERPASS_PUBLIC_URL. Use a complete http:// or https:// URL.');
    }
    $portalBaseUrl = $publicBaseUrl . $portalPath;
} else {
    $portalBaseUrl = $requestScheme . '://' . $driverHost . $portalPath;
}
$selectedDestination = (string) ($_GET['destination'] ?? '');
$registrationQrUrl = $portalBaseUrl . '?student_portal=1';
if (isset($destinations[$selectedDestination])) {
    $registrationQrUrl .= '&destination=' . rawurlencode($selectedDestination);
}
$driverQrUrl = $registrationQrUrl;
$maintenanceRows = $pdo->prepare('SELECT origin, destination, distance_km, fare, updated_at
    FROM fare_matrix WHERE fare_month = ? ORDER BY origin, destination LIMIT 50');
$maintenanceRows->execute([$fareMonth]);
$maintenanceRows = $maintenanceRows->fetchAll();
if (isset($_GET['fare_saved'])) {
    $notice = 'Fare matrix saved for ' . $fareMonth . '.';
}
if (isset($_GET['trip_saved'])) {
    $notice = 'Fare confirmed and saved to the database.';
}
function peso(float $amount): string { return '₱' . number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CommuterPass | Student Fare Scanner</title>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
    :root {
        --ink: #14213d;
        --blue: #176bdb;
        --sky: #eaf4ff;
        --green: #14804a;
        --red: #bb2d3b;
        --line: #dce5f0;
        --muted: #62728a;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        font-family: Inter, Segoe UI, Arial, sans-serif;
        background: #f6f9fd;
        color: var(--ink);
    }

    header {
        background: linear-gradient(120deg, #10234c, #176bdb);
        color: white;
        padding: 27px max(24px, calc((100% - 1100px)/2));
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .brand {
        font-weight: 800;
        letter-spacing: -.5px;
        font-size: 23px;
    }

    .brand span {
        color: #9ee4ff;
    }

    .badge {
        background: #ffffff1f;
        padding: 8px 13px;
        border-radius: 99px;
        font-size: 13px;
    }

    main {
        max-width: 1100px;
        margin: 28px auto 55px;
        padding: 0 20px;
    }

    h1 {
        margin: 0 0 5px;
        font-size: 28px;
    }

    .intro {
        margin: 0 0 23px;
        color: var(--muted);
    }

    .grid {
        display: grid;
        grid-template-columns: 1.1fr .9fr;
        gap: 20px;
    }

    .card {
        background: #fff;
        border: 1px solid var(--line);
        box-shadow: 0 5px 18px #2538580b;
        border-radius: 16px;
        padding: 22px;
    }

    h2 {
        font-size: 18px;
        margin: 0 0 16px;
    }

    label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        margin: 13px 0 6px;
        color: #34445f;
    }

    select,
    input {
        width: 100%;
        border: 1px solid #cbd7e6;
        border-radius: 9px;
        padding: 12px;
        font: inherit;
        color: var(--ink);
        background: white;
    }

    button {
        border: 0;
        border-radius: 9px;
        padding: 12px 16px;
        font: inherit;
        font-weight: 750;
        cursor: pointer;
        background: var(--blue);
        color: #fff;
    }

    button:hover {
        filter: brightness(.94);
    }

    .scan-btn {
        width: 100%;
        margin-top: 18px;
    }

    #qrReader {
        max-width: 420px;
        margin: 15px auto 0;
    }

    #qrReader:empty {
        display: none;
    }

    .secondary {
        background: #eaf4ff;
        color: #1456aa;
        margin-top: 10px;
        width: 100%;
    }

    .registration-link {
        display: block;
        text-align: center;
        text-decoration: none;
        margin-top: 10px;
    }

    .fare-box {
        background: var(--sky);
        border-radius: 11px;
        padding: 14px;
        margin-top: 17px;
    }

    .fare-box strong {
        font-size: 24px;
    }

    .small {
        color: var(--muted);
        font-size: 13px;
        margin-top: 4px;
    }

    .alert {
        padding: 14px 17px;
        border-radius: 10px;
        margin: 0 0 20px;
        font-weight: 600;
    }

    .success {
        background: #e8f8ef;
        color: #0d693b;
    }

    .error {
        background: #fff0f1;
        color: #9c2632;
    }

    .stat-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 13px;
        margin-bottom: 17px;
    }

    .stat {
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 15px;
    }

    .stat b {
        display: block;
        font-size: 24px;
        margin-top: 4px;
    }

    .stat span {
        font-size: 12px;
        color: var(--muted);
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: .5px;
    }

    .qrs {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-top: 20px;
    }

    .qr-card {
        text-align: center;
        padding: 15px 8px;
    }

    .qr {
        display: flex;
        justify-content: center;
        margin: 9px 0;
    }

    .qr-card b {
        font-size: 14px;
    }

    .qr-card p {
        margin: 4px 0;
        color: var(--muted);
        font-size: 12px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    th {
        text-align: left;
        color: var(--muted);
        font-size: 11px;
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    th,
    td {
        padding: 13px 8px;
        border-bottom: 1px solid var(--line);
    }

    .amount {
        font-weight: 800;
        color: var(--green);
    }

    .empty {
        color: var(--muted);
        text-align: center;
        padding: 30px;
    }

    @media(max-width:760px) {
        .grid {
            grid-template-columns: 1fr
        }

        .qrs {
            grid-template-columns: 1fr
        }

        header {
            padding: 20px
        }

        .badge {
            display: none
        }

        main {
            margin-top: 20px
        }
    }
    </style>
</head>

<body>
    <header>
        <div class="brand">Commuter<span>Pass</span></div>
        <div class="badge">Student Discount · Driver Console</div>
    </header>
    <main>
        <h1>Student commuter transactions</h1>
        <p class="intro">The driver selects the destination, then scans the commuter QR. Student QR codes receive a 20%
            discount; regular QR codes pay the full fare.</p>
        <?php if ($notice): ?><div class="alert <?= $noticeType ?>"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['student_portal'])): ?>
        <section class="card" style="margin-bottom:20px">
            <h2>Student Portal — Register or Log In</h2>
            <?php if (isset($destinations[$selectedDestination])): ?><div class="fare-box"><b>Selected destination:</b>
                <?= htmlspecialchars($selectedDestination) ?> · Distance
                <?= $routeDistances[$selectedDestination] < 1
                    ? number_format($routeDistances[$selectedDestination] * 1000, 0) . ' meters'
                    : number_format($routeDistances[$selectedDestination], 1) . ' km' ?> · Regular fare
                <?= peso($destinations[$selectedDestination]) ?></div><?php endif; ?>
            <?php if ($loggedInStudent): ?>
            <p><b><?= htmlspecialchars($loggedInStudent['name']) ?></b> ·
                <?= htmlspecialchars($loggedInStudent['email']) ?></p>
            <div class="fare-box" style="text-align:center">
                <b><?= $loggedInStudent['eligible'] ? 'Approved: 20% student discount' : 'Not approved for a discount' ?></b>
                <div class="small">School student ID:
                    <?= htmlspecialchars($loggedInStudent['school_student_number'] ?? '') ?></div>
                <div class="qr" id="studentPassQr"></div>
                <div class="small">Student pass: <?= htmlspecialchars($loggedInStudent['id']) ?> ·
                    <?= htmlspecialchars($loggedInStudent['school_year'] ?? '') ?> ·
                    <?= htmlspecialchars(ucfirst($loggedInStudent['semester'] ?? '')) ?> semester · Expires:
                    <?= htmlspecialchars($loggedInStudent['expires_at']) ?></div>
            </div>
            <?php else: ?>
            <div class="grid">
                <form method="post" class="card">
                    <h2>Register student</h2><input type="hidden" name="action" value="register_student">
                    <label>Name</label><input name="name" required><label>Student email</label><input type="email"
                        name="email" required><label>School</label><input name="school" required><label>School student
                        ID number</label><input name="school_student_number" maxlength="50"
                        pattern="[A-Za-z0-9][A-Za-z0-9/-]{0,49}" autocomplete="off" required>
                    <label>School year (YYYY-YYYY)</label><input name="school_year" type="text"
                        pattern="[0-9]{4}-[0-9]{4}" placeholder="2026-2027"
                        title="Use consecutive years, e.g. 2026-2027" required>
                    <label>Semester</label><select name="semester" required>
                        <option value="">Choose semester</option>
                        <option value="1st">1st semester</option>
                        <option value="2nd">2nd semester</option>
                        <option value="summer">Summer</option>
                    </select><label>Password</label><input type="password" name="password" minlength="6"
                        required><button style="margin-top:16px">Register & get Student QR</button>
                </form>
                <form method="post" class="card">
                    <h2>Student log in</h2><input type="hidden" name="action" value="student_login">
                    <label>Student email</label><input type="email" name="email" required>
                    <label>School student ID number</label><input name="school_student_number" maxlength="50"
                        pattern="[A-Za-z0-9][A-Za-z0-9/-]{0,49}" autocomplete="off" required>
                    <label>School year (YYYY-YYYY)</label><input name="school_year" type="text"
                        pattern="[0-9]{4}-[0-9]{4}" placeholder="2026-2027"
                        title="Use consecutive years, e.g. 2026-2027" required>
                    <label>Semester</label><select name="semester" required>
                        <option value="">Choose semester</option>
                        <option value="1st">1st semester</option>
                        <option value="2nd">2nd semester</option>
                        <option value="summer">Summer</option>
                    </select><label>Password</label><input type="password" name="password" required>
                    <button style="margin-top:16px">Log in</button>
                    <p class="small">New accounts are approved in this starter demo and expire after one year.</p>
                </form>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <div class="grid">
            <section class="card">
                <h2>Driver fare scanner</h2>
                <form method="post" id="scanForm"><input type="hidden" name="action" value="scan">
                    <input type="hidden" name="destination" id="destination">
                    <label for="originLocation">Route 1 / first location</label>
                    <input id="originLocation" list="calbayogOrigins" value="Calbayog City Proper"
                        placeholder="Type route 1 location" autocomplete="off" required>
                    <datalist id="calbayogOrigins">
                        <?php foreach ($firstLocationPlaces as $place): ?>
                        <option value="<?= htmlspecialchars($place) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <label for="destinationLocation">Route 2 / destination</label>
                    <input id="destinationLocation" list="calbayogDestinations"
                        placeholder="Type route 2 destination, e.g. Acedillo" autocomplete="off" required>
                    <datalist id="calbayogDestinations">
                        <?php foreach ($destinationPlaces as $place): ?>
                        <option value="<?= htmlspecialchars($place) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="fare-box" id="farePreview"><strong id="amountToPay">₱0.00</strong>
                        <div class="small" id="fareText">Select a destination, then scan a commuter QR.</div>
                        <div class="small" id="fareBreakdown">Distance: 0 km · Regular fare: ₱0.00 · Discount: ₱0.00 ·
                            Amount to pay:
                            ₱0.00</div>
                    </div>
                    <label for="student_id">Student ID for discount</label>
                    <input name="student_id" id="student_id"
                        placeholder="School ID or pass: STU-123456 · Regular: REG-2001" autocomplete="off">
                    <button type="button" class="scan-btn" id="startQrScanner">Scan commuter QR with camera</button>
                    <div id="qrReader"></div>
                    <button type="button" class="secondary" id="checkFare">Apply student discount</button>
                    <div class="fare-box" id="scanResult" hidden>
                        <strong id="riderName">Waiting for QR scan</strong>
                        <div class="small" id="riderStatus"></div>
                        <div class="small" id="riderDue"></div>
                        <a class="secondary registration-link" id="registrationLink"
                            href="<?= htmlspecialchars($driverQrUrl) ?>">Open Student Registration</a>
                    </div>
                    <button type="submit" class="secondary" id="confirmFare" hidden>Confirm cash fare & save
                        transaction</button>
                </form>
                <p class="small">Choose a destination, then scan or enter the student ID. A valid student ID
                    automatically receives 20% off; regular IDs pay the full fare.</p>
            </section>
            <aside class="card">
                <h2>Driver collection today</h2>
                <div class="stat-row">
                    <div class="stat"><span>Transactions</span><b><?= count($todayTransactions) ?></b></div>
                    <div class="stat"><span>Collected</span><b><?= peso($totalCollected) ?></b></div>
                </div>
                <p class="small">Configured route distances use the fare table. Both student and regular fares collected
                    are added to the driver total.</p>
                <div class="fare-box" style="text-align:center">
                    <b>Commuter registration QR</b>
                    <div class="qr" id="driverQr"></div>
                    <div class="small" id="driverQrHelp">Scan this QR with any cellphone to open Student
                        Registration or Log In. Use a public URL in COMMUTERPASS_PUBLIC_URL for access outside your
                        Wi-Fi.</div>
                    <p class="small"><a id="driverQrLink" href="<?= htmlspecialchars($driverQrUrl) ?>">Open Student
                            Registration</a></p>
                    <p class="small">The QR opens the website URL. A public HTTPS URL is required for phones outside
                        the computer's Wi-Fi network.</p>
                </div>
            </aside>
        </div>
        <section class="card" style="margin-top:20px">
            <h2>Recent transactions</h2><?php if (!$transactions): ?><div class="empty">No fares recorded yet. Scan a
                passenger QR to begin.</div><?php else: ?><div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Passenger</th>
                            <th>Fare type</th>
                            <th>Destination</th>
                            <th>Regular</th>
                            <th>Discount</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach (array_slice($transactions, 0, 15) as $t): ?><tr>
                            <td><?= htmlspecialchars(date('M d, h:i A', strtotime($t['created_at']))) ?></td>
                            <td><b><?= htmlspecialchars($t['student_name']) ?></b><br><span
                                    class="small"><?= htmlspecialchars($t['student_id']) ?></span></td>
                            <td><?= htmlspecialchars($t['passenger_type'] ?? 'Student') ?></td>
                            <td><?= htmlspecialchars($t['destination']) ?></td>
                            <td><?= peso((float)$t['regular_fare']) ?></td>
                            <td><?= peso((float)$t['discount']) ?></td>
                            <td class="amount"><?= peso((float)$t['paid_fare']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                </table>
            </div><?php endif; ?>
        </section>
        <section class="card" style="margin-top:20px">
            <h2>Maintenance of Route - Fare Matrix</h2>
            <p class="small">Fare values are saved separately for every month. Select a month to edit its route fares.
            </p>
            <form method="post" class="grid">
                <input type="hidden" name="action" value="save_fare_matrix">
                <div>
                    <label for="fare_month">Fare month</label>
                    <input type="month" id="fare_month" name="fare_month" value="<?= htmlspecialchars($fareMonth) ?>"
                        required>
                    <label for="matrix_origin">Route 1</label>
                    <input id="matrix_origin" name="origin" list="calbayogOrigins" placeholder="Select route 1 location"
                        required>
                </div>
                <div>
                    <label for="matrix_destination">Route 2</label>
                    <input id="matrix_destination" name="destination_place" list="calbayogDestinations"
                        placeholder="Select route 2 destination" required>
                    <label for="matrix_distance">Distance in kilometers</label>
                    <input type="number" id="matrix_distance" name="distance_km" min="0" step="0.01"
                        placeholder="Example: 1.50" required>
                </div>
                <div>
                    <label for="matrix_fare">Fare (₱)</label>
                    <input type="number" id="matrix_fare" name="fare" min="0" step="0.01" placeholder="Example: 15.00"
                        required>
                    <button type="submit" style="margin-top:16px">Save fare for this month</button>
                </div>
            </form>
            <h3>Fare matrix for <?= htmlspecialchars($fareMonth) ?></h3>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Route 1</th>
                            <th>Route 2</th>
                            <th>Distance</th>
                            <th>Fare</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($maintenanceRows as $row): ?><tr>
                            <td><?= htmlspecialchars($row['origin']) ?></td>
                            <td><?= htmlspecialchars($row['destination']) ?></td>
                            <td><?= number_format((float) $row['distance_km'], 2) ?> km</td>
                            <td class="amount"><?= peso((float) $row['fare']) ?></td>
                            <td><?= htmlspecialchars($row['updated_at']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
    </main>
    <script>
    const fares = <?= json_encode($destinations) ?>;
    const farePerKilometer = <?= json_encode($farePerKilometer) ?>;
    const routeDistances = <?= json_encode($routeDistances) ?>;
    const discountEligible =
        <?= json_encode(array_map(fn($passenger) => $passenger['discount_eligible'], $passengers)) ?>;
    const riderRecords =
        <?= json_encode(array_map(fn($passenger) => ['name' => $passenger['name'], 'type' => $passenger['type'], 'eligible' => $passenger['discount_eligible'], 'schoolStudentNumber' => $passenger['school_student_number'] ?? ''], $passengers)) ?>;
    const schoolStudentIdMap = <?= json_encode($schoolStudentIdMap) ?>;
    const destination = document.getElementById('destination'),
        originLocation = document.getElementById('originLocation'),
        destinationLocation = document.getElementById('destinationLocation'),
        preview = document.getElementById('farePreview'),
        fareText = document.getElementById('fareText'),
        fareBreakdown = document.getElementById('fareBreakdown'),
        studentInput = document.getElementById('student_id'),
        scanResult = document.getElementById('scanResult'),
        riderName = document.getElementById('riderName'),
        riderStatus = document.getElementById('riderStatus'),
        riderDue = document.getElementById('riderDue'),
        registrationLink = document.getElementById('registrationLink'),
        confirmFare = document.getElementById('confirmFare');

    function formatDistance(distance) {
        return distance < 1 ? Math.round(distance * 1000) + ' meters' : distance.toFixed(1) + ' km';
    }

    originLocation.addEventListener('focus', () => originLocation.select(), {
        once: true
    });

    function updateFarePreview() {
        const regularFare = Number(fares[destination.value] || 0);
        const distance = Number(routeDistances[destination.value] || 0);
        const isStudent = studentInput.value && discountEligible[studentInput.value];
        const discount = isStudent ? regularFare * .20 : 0;
        const fare = regularFare - discount;
        document.getElementById('amountToPay').textContent = '₱' + fare.toFixed(2);
        fareBreakdown.textContent = 'Distance: ' + formatDistance(distance) + ' · Regular fare: ₱' + regularFare
            .toFixed(2) +
            ' · Discount: ₱' + discount.toFixed(2) + ' · Amount to pay: ₱' + fare.toFixed(2);
        fareText.textContent = !destination.value ? 'Select a destination, then scan a commuter QR.' :
            !studentInput.value ? 'Regular fare shown. Scan QR to apply a student discount when eligible.' :
            isStudent ? 'Student fare — 20% discount applied.' : 'Regular commuter — full regular fare.';
    }

    function showScannedRider(id) {
        const rider = riderRecords[id];
        scanResult.hidden = false;
        if (!id) {
            riderName.textContent = 'Regular passenger';
            riderStatus.textContent = 'No Student ID entered — full regular fare';
            riderDue.textContent = 'DUE TODAY: Distance ' + formatDistance(Number(routeDistances[destination.value] ||
                    0)) + ' · Standard fare ₱' + Number(fares[destination.value] || 0).toFixed(2) +
                ' · Discount ₱0.00';
            confirmFare.hidden = !destination.value;
            return;
        }
        if (!rider) {
            riderName.textContent = 'Invalid commuter QR';
            riderStatus.textContent = 'This QR is not registered. Please scan again (maximum 3 retries).';
            riderDue.textContent = 'No fare is ready.';
            confirmFare.hidden = true;
            return;
        }
        registrationLink.hidden = false;
        const standardFare = Number(fares[destination.value] || 0);
        const distance = Number(routeDistances[destination.value] || 0);
        const discount = rider.eligible ? standardFare * .20 : 0;
        const due = standardFare - discount;
        riderName.textContent = 'Rider: ' + rider.name;
        riderStatus.textContent = rider.eligible ? 'STUDENT COMMUTER — approved 20% discount' :
            'REGULAR COMMUTER — standard fare';
        riderDue.textContent = 'DUE TODAY: Distance ' + formatDistance(distance) + ' · Standard fare ₱' + standardFare
            .toFixed(2) + ' · Discount ₱' + discount
            .toFixed(2) + ' · Cash due ₱' + due.toFixed(2);
        confirmFare.hidden = false;
    }

    function syncRouteSelection() {
        const origin = originLocation.value.trim();
        const target = destinationLocation.value.trim();
        const route = origin && target ? origin + ' -> ' + target : '';
        destination.value = Object.prototype.hasOwnProperty.call(fares, route) ? route : '';
        updateFarePreview();
        if (!destination.value) {
            fareText.textContent = origin && target ?
                'This route has no distance configured yet. Choose a listed destination.' :
                'Select both a location and destination to show the fare.';
        }
        if (destination.value) {
            showScannedRider(studentInput.value);
        }
        updateDestinationQr();
    }
    originLocation.addEventListener('input', syncRouteSelection);
    originLocation.addEventListener('change', syncRouteSelection);
    destinationLocation.addEventListener('input', syncRouteSelection);
    destinationLocation.addEventListener('change', syncRouteSelection);

    function resolveCommuterCode(rawValue) {
        const parts = String(rawValue || '').trim().replace(/^COMMUTERPASS:/i, '').split('|');
        const scannedId = parts[0].trim();
        const commuterId = riderRecords[scannedId] ? scannedId : schoolStudentIdMap[scannedId];
        if (!commuterId || !riderRecords[commuterId]) return '';

        const linkedSchoolId = parts.find(part => /^SCHOOLID:/i.test(part));
        if (linkedSchoolId) {
            const qrSchoolId = linkedSchoolId.replace(/^SCHOOLID:/i, '').trim().toUpperCase();
            if (qrSchoolId !== String(riderRecords[commuterId].schoolStudentNumber || '').toUpperCase()) return '';
        }
        return commuterId;
    }

    studentInput.addEventListener('input', () => {
        const scannedValue = studentInput.value.trim();
        const commuterId = resolveCommuterCode(scannedValue);
        if (commuterId) studentInput.value = commuterId;
        updateFarePreview();
        if (commuterId) showScannedRider(commuterId);
    });

    const portalBaseUrl = <?= json_encode($portalBaseUrl) ?>;
    const driverQr = new QRCode(document.getElementById('driverQr'), {
        text: <?= json_encode($driverQrUrl) ?>,
        width: 210,
        height: 210
    });
    const driverQrLink = document.getElementById('driverQrLink');
    const driverQrHelp = document.getElementById('driverQrHelp');

    function updateDestinationQr() {
        const selected = destination.value;
        const validDestination = Object.prototype.hasOwnProperty.call(fares, selected) ? selected : '';
        const portalUrl = validDestination ? portalBaseUrl + '?student_portal=1&destination=' + encodeURIComponent(
                validDestination) :
            portalBaseUrl + '?student_portal=1';
        driverQr.clear();
        driverQr.makeCode(portalUrl);
        driverQrLink.href = portalUrl;
        driverQrHelp.textContent = validDestination ? 'Scan this QR to open Registration or Log In for ' +
            validDestination + '.' :
            'Scan this QR with another cellphone to open Student Registration or Log In.';
    }
    const studentPassQr = document.getElementById('studentPassQr');
    if (studentPassQr) {
        new QRCode(studentPassQr, {
            text: <?= json_encode('COMMUTERPASS:' . ($loggedInStudent['id'] ?? '') . '|SCHOOLID:' . ($loggedInStudent['school_student_number'] ?? '')) ?>,
            width: 180,
            height: 180
        });
    }

    function checkFare() {
        if (!destination.value) {
            alert('Driver: choose the passenger destination first.');
            return;
        }
        const commuterId = resolveCommuterCode(studentInput.value);
        if (commuterId) studentInput.value = commuterId;
        updateFarePreview();
        showScannedRider(studentInput.value);
    }
    document.getElementById('checkFare').addEventListener('click', checkFare);

    const startQrScanner = document.getElementById('startQrScanner');
    const qrReader = document.getElementById('qrReader');
    let qrScanner = null;
    let scanInProgress = false;

    function normalizeScannedCode(rawValue) {
        const value = String(rawValue || '').trim();
        if (!value) return '';
        return value;
    }

    function handleScannedValue(rawValue) {
        const normalized = normalizeScannedCode(rawValue);
        if (!normalized) return false;
        if (/^https?:\/\//i.test(normalized)) {
            window.location.assign(normalized);
            return true;
        }
        const commuterId = resolveCommuterCode(normalized);
        if (!commuterId) {
            studentInput.value = normalized;
            checkFare();
            return true;
        }
        studentInput.value = commuterId;
        checkFare();
        return true;
    }

    startQrScanner.addEventListener('click', async () => {
        if (scanInProgress || qrScanner) return;
        if (!window.Html5Qrcode) {
            alert('The QR scanner library could not load. Enter the commuter ID manually.');
            return;
        }

        scanInProgress = true;
        qrScanner = new Html5Qrcode('qrReader');

        try {
            await qrScanner.start({
                    facingMode: 'environment'
                }, {
                    fps: 10,
                    qrbox: {
                        width: 220,
                        height: 220
                    }
                },
                async decodedText => {
                        const accepted = handleScannedValue(decodedText);
                        if (!accepted) return;

                        try {
                            await qrScanner.stop();
                        } catch (error) {}
                        try {
                            qrScanner.clear();
                        } catch (error) {}
                        qrScanner = null;
                        scanInProgress = false;
                    },
                    () => {}
            );
        } catch (error) {
            alert('Camera access failed. Check browser permission or enter the commuter ID manually.');
            qrScanner = null;
            scanInProgress = false;
        }
    });
    document.getElementById('scanForm').addEventListener('submit', e => {
        if (!destination.value) {
            e.preventDefault();
            alert('Choose a destination first. Student ID is optional for regular fare.');
        }
    });
    </script>
</body>

</html>