<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = db();

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
            name,
            slug
        )
        VALUES
        (
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            slug = VALUES(slug)
    ");


    $divisionIds = [];

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


        $divisionInsert->execute([
            $id,
            $name,
            $slug
        ]);


        $divisionIds[$id] = true;

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
            division_id,
            name,
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
            ?
        )
        ON DUPLICATE KEY UPDATE
            division_id = VALUES(division_id),
            name = VALUES(name),
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
            $divisionId,
            $name,
            $slug,
            $latitude,
            $longitude
        ]);


        $districtIds[$id] = true;

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
            ?
        )
        ON DUPLICATE KEY UPDATE
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


        $upazilaInsert->execute([
            $id,
            $districtId,
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