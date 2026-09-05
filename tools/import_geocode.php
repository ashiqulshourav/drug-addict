<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = db();

$hasColumn = static function (
    PDO $pdo,
    string $table,
    string $column
): bool {
    $stmt = $pdo->prepare(
        "
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        "
    );

    $stmt->execute([
        $table,
        $column
    ]);

    return (int) $stmt->fetchColumn() > 0;
};

if (!$hasColumn($pdo, 'divisions', 'source_id')) {
    $pdo->exec(
        'ALTER TABLE divisions ADD source_id SMALLINT UNSIGNED NULL AFTER id'
    );
    $pdo->exec(
        'UPDATE divisions SET source_id = id WHERE source_id IS NULL'
    );
    $pdo->exec(
        'ALTER TABLE divisions MODIFY source_id SMALLINT UNSIGNED NOT NULL'
    );
    $pdo->exec(
        'ALTER TABLE divisions ADD UNIQUE KEY uq_division_source_id (source_id)'
    );
}

if (!$hasColumn($pdo, 'divisions', 'bn_name')) {
    $pdo->exec(
        'ALTER TABLE divisions ADD bn_name VARCHAR(80) NULL AFTER name'
    );
}

if (!$hasColumn($pdo, 'divisions', 'latitude')) {
    $pdo->exec(
        'ALTER TABLE divisions ADD latitude DECIMAL(10,7) NULL'
    );
}

if (!$hasColumn($pdo, 'divisions', 'longitude')) {
    $pdo->exec(
        'ALTER TABLE divisions ADD longitude DECIMAL(10,7) NULL'
    );
}

if (!$hasColumn($pdo, 'districts', 'source_id')) {
    $pdo->exec(
        'ALTER TABLE districts ADD source_id SMALLINT UNSIGNED NULL AFTER id'
    );
    $pdo->exec(
        'UPDATE districts SET source_id = id WHERE source_id IS NULL'
    );
    $pdo->exec(
        'ALTER TABLE districts MODIFY source_id SMALLINT UNSIGNED NOT NULL'
    );
    $pdo->exec(
        'ALTER TABLE districts ADD UNIQUE KEY uq_district_source_id (source_id)'
    );
}

if (!$hasColumn($pdo, 'districts', 'bn_name')) {
    $pdo->exec(
        'ALTER TABLE districts ADD bn_name VARCHAR(80) NULL AFTER name'
    );
}

if (!$hasColumn($pdo, 'districts', 'latitude')) {
    $pdo->exec(
        'ALTER TABLE districts ADD latitude DECIMAL(10,7) NULL'
    );
}

if (!$hasColumn($pdo, 'districts', 'longitude')) {
    $pdo->exec(
        'ALTER TABLE districts ADD longitude DECIMAL(10,7) NULL'
    );
}

if (!$hasColumn($pdo, 'upazilas', 'source_id')) {
    $pdo->exec(
        'ALTER TABLE upazilas ADD source_id INT UNSIGNED NULL AFTER id'
    );
    $pdo->exec(
        'UPDATE upazilas SET source_id = id WHERE source_id IS NULL'
    );
    $pdo->exec(
        'ALTER TABLE upazilas MODIFY source_id INT UNSIGNED NOT NULL'
    );
    $pdo->exec(
        'ALTER TABLE upazilas ADD UNIQUE KEY uq_upazila_source_id (source_id)'
    );
}

if (!$hasColumn($pdo, 'upazilas', 'latitude')) {
    $pdo->exec(
        'ALTER TABLE upazilas ADD latitude DECIMAL(10,7) NULL'
    );
}

if (!$hasColumn($pdo, 'upazilas', 'longitude')) {
    $pdo->exec(
        'ALTER TABLE upazilas ADD longitude DECIMAL(10,7) NULL'
    );
}

$pdo->exec(
    "
    ALTER TABLE divisions
        CONVERT TO CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    "
);

$pdo->exec(
    "
    ALTER TABLE districts
        CONVERT TO CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    "
);

$pdo->exec(
    "
    ALTER TABLE upazilas
        CONVERT TO CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci
    "
);

$pdo->exec(
    'UPDATE divisions SET source_id = 1000 + id'
);

$pdo->exec(
    'UPDATE upazilas SET source_id = 1000000 + id'
);

$jsonFile = __DIR__ . '/../data/db_geocode.json';

if (!file_exists($jsonFile)) {
    die("db_geocode.json পাওয়া যায়নি.\n");
}

$json = file_get_contents($jsonFile);

if ($json === false) {
    die("db_geocode.json read করা যায়নি.\n");
}

$data = json_decode($json, true);

if (!is_array($data)) {
    die("JSON parse করা যায়নি.\n");
}

echo "<pre>";
echo "JSON loaded successfully.\n\n";


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function slugify(string $value): string
{
    $value = trim(strtolower($value));

    $value = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $value
    ) ?? '';

    return trim($value, '-');
}


function getSlug(
    array $row,
    string $prefix,
    int $id
): string {
    $slug = trim(
        (string)($row['slug'] ?? '')
    );

    if ($slug !== '') {
        return strtolower($slug);
    }

    $slug = slugify(
        (string)($row['name'] ?? '')
    );

    return $slug !== ''
        ? $slug
        : $prefix . '-' . $id;
}


function getLatitude(array $row): ?float
{
    if (
        isset($row['latitude']) &&
        $row['latitude'] !== ''
    ) {
        return (float)$row['latitude'];
    }

    if (
        isset($row['lat']) &&
        $row['lat'] !== ''
    ) {
        return (float)$row['lat'];
    }

    return null;
}


function getLongitude(array $row): ?float
{
    if (
        isset($row['longitude']) &&
        $row['longitude'] !== ''
    ) {
        return (float)$row['longitude'];
    }

    if (
        isset($row['lng']) &&
        $row['lng'] !== ''
    ) {
        return (float)$row['lng'];
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Read JSON tables
|--------------------------------------------------------------------------
|
| Expected structure:
|
| divisions
| districts
| upazilas
|
| police_stations is intentionally ignored.
|
|--------------------------------------------------------------------------
*/

$divisions = [];
$districts = [];
$upazilas = [];

$policeStations = [];


foreach ($data as $table) {

    if (!is_array($table)) {
        continue;
    }

    if (
        ($table['type'] ?? '') !== 'table'
    ) {
        continue;
    }

    if (
        !isset(
            $table['name'],
            $table['data']
        )
    ) {
        continue;
    }

    $tableName = strtolower(
        (string)$table['name']
    );


    if ($tableName === 'divisions') {

        $divisions = is_array($table['data'])
            ? $table['data']
            : [];


    } elseif ($tableName === 'districts') {

        $districts = is_array($table['data'])
            ? $table['data']
            : [];


    } elseif ($tableName === 'upazilas') {

        $upazilas = is_array($table['data'])
            ? $table['data']
            : [];


    } elseif ($tableName === 'police_stations') {

        $policeStations = is_array($table['data'])
            ? $table['data']
            : [];
    }
}


echo "Divisions: "
    . count($divisions)
    . "\n";

echo "Districts: "
    . count($districts)
    . "\n";

echo "Upazilas: "
    . count($upazilas)
    . "\n";

echo "Police stations in JSON: "
    . count($policeStations)
    . "\n\n";


if (
    !$divisions ||
    !$districts ||
    !$upazilas
) {
    die(
        "Expected divisions/districts/upazilas data পাওয়া যায়নি.\n"
    );
}


/*
|--------------------------------------------------------------------------
| Start transaction
|--------------------------------------------------------------------------
*/

$pdo->beginTransaction();

try {

    /*
    |--------------------------------------------------------------------------
    | 1. Divisions
    |--------------------------------------------------------------------------
    */

    $divisionInsert = $pdo->prepare("
        INSERT INTO divisions
        (
            id,
            source_id,
            name,
            bn_name,
            slug,
            latitude,
            longitude
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            source_id = VALUES(source_id),
            name = VALUES(name),
            bn_name = VALUES(bn_name),
            slug = VALUES(slug),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude)
    ");

    $divisionIds = [];

    $divisionBySlug = $pdo->query(
        'SELECT id, slug FROM divisions'
    )->fetchAll();

    $divisionDatabaseIds = [];

    foreach ($divisionBySlug as $divisionRow) {
        $divisionDatabaseIds[
            strtolower((string) $divisionRow['slug'])
        ] = (int) $divisionRow['id'];
    }

    $divisionCount = 0;


    foreach ($divisions as $row) {

        $id = (int)(
            $row['id'] ?? 0
        );

        $name = trim(
            (string)(
                $row['name'] ?? ''
            )
        );

        $bnName = trim(
            (string)(
                $row['bn_name'] ?? ''
            )
        );


        if (
            $id <= 0 ||
            $name === ''
        ) {
            continue;
        }


        $slug = getSlug(
            $row,
            'division',
            $id
        );

        $databaseId =
            $divisionDatabaseIds[strtolower($slug)]
            ?? $id;


        $divisionInsert->execute([
            $databaseId,
            $id,
            $name,
            $bnName !== '' ? $bnName : null,
            $slug,
            getLatitude($row),
            getLongitude($row)
        ]);


        $divisionIds[$id] = $databaseId;

        $divisionCount++;
    }


    /*
    |--------------------------------------------------------------------------
    | 2. Districts
    |--------------------------------------------------------------------------
    */

    $districtInsert = $pdo->prepare("
        INSERT INTO districts
        (
            id,
            source_id,
            division_id,
            name,
            bn_name,
            slug,
            latitude,
            longitude
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            source_id = VALUES(source_id),
            division_id = VALUES(division_id),
            name = VALUES(name),
            bn_name = VALUES(bn_name),
            slug = VALUES(slug),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude)
    ");


    $districtIds = [];

    $districtCount = 0;


    foreach ($districts as $row) {

        $id = (int)(
            $row['id'] ?? 0
        );

        $divisionId = (int)(
            $row['division_id'] ?? 0
        );

        $name = trim(
            (string)(
                $row['name'] ?? ''
            )
        );

        $bnName = trim(
            (string)(
                $row['bn_name'] ?? ''
            )
        );


        if (
            $id <= 0 ||
            $divisionId <= 0 ||
            $name === ''
        ) {
            continue;
        }


        /*
         * Make sure parent division exists.
         */

        if (
            !isset(
                $divisionIds[$divisionId]
            )
        ) {
            throw new RuntimeException(
                "District {$id} references missing division {$divisionId}."
            );
        }


        $slug = getSlug(
            $row,
            'district',
            $id
        );


        $latitude = getLatitude($row);

        $longitude = getLongitude($row);


        $districtInsert->execute([
            $id,
            $id,
            $divisionIds[$divisionId],
            $name,
            $bnName !== '' ? $bnName : null,
            $slug,
            $latitude,
            $longitude
        ]);


        $districtIds[$id] = $id;

        $districtCount++;
    }


    /*
    |--------------------------------------------------------------------------
    | 3. Upazilas
    |--------------------------------------------------------------------------
    */

    $upazilaInsert = $pdo->prepare("
        INSERT INTO upazilas
        (
            id,
            source_id,
            district_id,
            name,
            bn_name,
            slug,
            latitude,
            longitude
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            source_id = VALUES(source_id),
            district_id = VALUES(district_id),
            name = VALUES(name),
            bn_name = VALUES(bn_name),
            slug = VALUES(slug),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude)
    ");


    $upazilaCount = 0;


    foreach ($upazilas as $row) {

        $id = (int)(
            $row['id'] ?? 0
        );

        $districtId = (int)(
            $row['district_id'] ?? 0
        );

        $name = trim(
            (string)(
                $row['name'] ?? ''
            )
        );

        $bnName = trim(
            (string)(
                $row['bn_name'] ?? ''
            )
        );


        if (
            $id <= 0 ||
            $districtId <= 0 ||
            $name === ''
        ) {
            continue;
        }


        /*
         * Make sure parent district exists.
         */

        if (
            !isset(
                $districtIds[$districtId]
            )
        ) {
            throw new RuntimeException(
                "Upazila {$id} references missing district {$districtId}."
            );
        }


        $slug = getSlug(
            $row,
            'upazila',
            $id
        );


        $latitude = getLatitude($row);

        $longitude = getLongitude($row);

        $databaseDistrictId = $districtIds[$districtId];

        $existingUpazilaStmt = $pdo->prepare(
            '
            SELECT id
            FROM upazilas
            WHERE district_id = ?
              AND slug = ?
            LIMIT 1
            '
        );

        $existingUpazilaStmt->execute([
            $databaseDistrictId,
            $slug
        ]);

        $existingUpazilaId =
            $existingUpazilaStmt->fetchColumn();

        $databaseUpazilaId =
            $existingUpazilaId !== false
                ? (int) $existingUpazilaId
                : $id;


        $upazilaInsert->execute([
            $databaseUpazilaId,
            $id,
            $databaseDistrictId,
            $name,
            $bnName !== ''
                ? $bnName
                : null,
            $slug,
            $latitude,
            $longitude
        ]);


        $upazilaCount++;
    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */

    $divisionDbCount = (int)$pdo
        ->query(
            "SELECT COUNT(*) FROM divisions"
        )
        ->fetchColumn();


    $districtDbCount = (int)$pdo
        ->query(
            "SELECT COUNT(*) FROM districts"
        )
        ->fetchColumn();


    $upazilaDbCount = (int)$pdo
        ->query(
            "SELECT COUNT(*) FROM upazilas"
        )
        ->fetchColumn();


    $policeStationDbCount = (int)$pdo
        ->query(
            "SELECT COUNT(*) FROM police_stations"
        )
        ->fetchColumn();


    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    echo "Import completed successfully.\n\n";


    echo "Processed:\n";

    echo "  Divisions: "
        . $divisionCount
        . "\n";

    echo "  Districts: "
        . $districtCount
        . "\n";

    echo "  Upazilas: "
        . $upazilaCount
        . "\n\n";


    echo "Database totals:\n";

    echo "  Divisions: "
        . $divisionDbCount
        . "\n";

    echo "  Districts: "
        . $districtDbCount
        . "\n";

    echo "  Upazilas: "
        . $upazilaDbCount
        . "\n";

    echo "  Police stations: "
        . $policeStationDbCount
        . "\n\n";


    echo "Hierarchy:\n";

    echo "Division → District → Upazila\n\n";


    echo "No fake police stations were created.\n";

} catch (Throwable $e) {

    if (
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }


    echo "\nImport failed:\n";

    echo $e->getMessage() . "\n";
}


echo "</pre>";