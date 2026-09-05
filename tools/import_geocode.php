<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = db();

$jsonFile =
    __DIR__ .
    '/../data/db_geocode.json';

if (!file_exists($jsonFile)) {

    die(
        "db_geocode.json পাওয়া যায়নি।\n"
    );
}

$json =
    file_get_contents(
        $jsonFile
    );

$data =
    json_decode(
        $json,
        true
    );

if (
    !is_array($data)
) {

    die(
        "JSON parse করা যায়নি।\n"
    );
}


/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| এই dataset-এর structure আগে inspect করছি।
|
*/

echo "<pre>";

echo "JSON loaded successfully.\n\n";


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function slugify(
    string $value
): string {

    $value =
        trim(
            strtolower(
                $value
            )
        );

    $value =
        preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $value
        );

    return trim(
        $value,
        '-'
    );
}


/*
|--------------------------------------------------------------------------
| Find table data
|--------------------------------------------------------------------------
*/

$divisions = [];

$districts = [];

$upazilas = [];

$policeStations = [];

$sourceDivisionsById = [];

$sourceDistrictsById = [];


/*
|--------------------------------------------------------------------------
| The source JSON is a phpMyAdmin-style export.
|--------------------------------------------------------------------------
*/

foreach (
    $data as $table
) {

    if (
        !isset(
            $table['type']
        )
    ) {
        continue;
    }

    if (
        $table['type'] !== 'table'
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

    $name =
        strtolower(
            $table['name']
        );

    if (
        $name === 'divisions'
    ) {

        $divisions =
            $table['data'];

        foreach (
            $divisions as $row
        ) {
            $sourceId =
                isset($row['id'])
                    ? (int) $row['id']
                    : 0;

            if (
                $sourceId > 0
            ) {
                $sourceDivisionsById[$sourceId] =
                    trim(
                        (string)
                        (
                            $row['name']
                            ?? ''
                        )
                    );
            }
        }

    } elseif (
        $name === 'districts'
    ) {

        $districts =
            $table['data'];

        foreach (
            $districts as $row
        ) {
            $sourceId =
                isset($row['id'])
                    ? (int) $row['id']
                    : 0;

            if (
                $sourceId > 0
            ) {
                $sourceDistrictsById[$sourceId] =
                    trim(
                        (string)
                        (
                            $row['name']
                            ?? ''
                        )
                    );
            }
        }

    } elseif (
        $name === 'upazilas'
    ) {

        $upazilas =
            $table['data'];

    } elseif (
        $name === 'police_stations'
    ) {

        $policeStations =
            $table['data'];
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

echo "Police stations: "
    . count($policeStations)
    . "\n\n";


if (
    !$divisions ||
    !$districts ||
    !$upazilas
) {

    die(
        "Expected divisions/districts/upazilas data পাওয়া যায়নি।"
    );
}


$pdo->beginTransaction();

try {

    /*
    |--------------------------------------------------------------------------
    | Divisions
    |--------------------------------------------------------------------------
    */

    $divisionInsert =
        $pdo->prepare(
            "
            INSERT INTO divisions
                (
                    source_id,
                    name,
                    slug,
                    bn_name,
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
                name = VALUES(name),
                slug = VALUES(slug),
                bn_name = VALUES(bn_name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude)
            "
        );


    foreach (
        $divisions as $row
    ) {

        $name =
            trim(
                (string)
                (
                    $row['name']
                    ?? ''
                )
            );

        $bnName =
            trim(
                (string)
                (
                    $row['bn_name']
                    ?? ''
                )
            );


        if (
            $name === ''
        ) {
            continue;
        }

        $sourceId = (int) ($row['id'] ?? 0);
        if ($sourceId <= 0) {
            continue;
        }


        /*
         * Use English name as canonical name.
         */

        $slug =
            slugify(
                $name
            );


        $divisionInsert->execute([
            $sourceId,
            $name,
            $slug,
            $bnName !== '' ? $bnName : null,
            isset($row['lat']) ? (float) $row['lat'] : null,
            isset($row['lng']) ? (float) $row['lng'] : null
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Districts
    |--------------------------------------------------------------------------
    */

    $districtInsert =
        $pdo->prepare(
            "
            INSERT INTO districts
                (
                    source_id,
                    division_id,
                    name,
                    slug,
                    bn_name,
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
                division_id =
                    VALUES(division_id),
                name = VALUES(name),
                slug = VALUES(slug),
                bn_name = VALUES(bn_name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude)
            "
        );


    foreach (
        $districts as $row
    ) {

        $name =
            trim(
                (string)
                (
                    $row['name']
                    ?? ''
                )
            );


        $divisionId =
            (int)
            (
                $row['division_id']
                ?? 0
            );


        if (
            $name === '' ||
            $divisionId <= 0
        ) {
            continue;
        }

        $sourceId = (int) ($row['id'] ?? 0);
        if ($sourceId <= 0) {
            continue;
        }

        $sourceDivisionName =
            trim(
                (string)
                (
                    $sourceDivisionsById[$divisionId]
                    ?? ''
                )
            );

        $division = null;

        if (
            $sourceDivisionName !== ''
        ) {
            $divisionStmt =
                $pdo->prepare(
                    "
                    SELECT id

                    FROM divisions

                    WHERE source_id = ?

                    LIMIT 1
                    "
                );

            $divisionStmt->execute([
                $divisionId
            ]);

            $division =
                $divisionStmt->fetch();
        }

        if (
            !$division
        ) {
            $parent =
                $pdo->prepare(
                    "
                    SELECT id

                    FROM divisions

                    WHERE id = ?

                    LIMIT 1
                    "
                );

            $parent->execute([
                $divisionId
            ]);

            $division =
                $parent->fetch();
        }

        if (!$division) {
            continue;
        }


        $slug =
            slugify(
                $name
            );


        $districtInsert->execute([
            $sourceId,
            (int)
            $division['id'],

            $name,

            $slug,
            isset($row['bn_name']) ? trim((string) $row['bn_name']) : null,
            isset($row['lat']) ? (float) $row['lat'] : null,
            isset($row['lng']) ? (float) $row['lng'] : null
        ]);
    }

    $districtStationInsert =
        $pdo->prepare(
            "
            INSERT INTO police_stations
                (
                    district_id,
                    name,
                    latitude,
                    longitude
                )

            VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )

            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude)
            "
        );

    if ($policeStations) {
        foreach (
            $districts as $row
        ) {

        $districtName =
            trim(
                (string)
                (
                    $row['name']
                    ?? ''
                )
            );

        if (
            $districtName === ''
        ) {
            continue;
        }

        $districtStmt =
            $pdo->prepare(
                "
                SELECT id

                FROM districts

                WHERE name = ?

                LIMIT 1
                "
            );

        $districtStmt->execute([
            $districtName
        ]);

        $district =
            $districtStmt->fetch();

        if (!$district) {
            continue;
        }

        $stationName =
            $districtName . ' থানা';

        $lat =
            isset($row['lat'])
                ? (float) $row['lat']
                : null;

        $lng =
            isset($row['lng'])
                ? (float) $row['lng']
                : null;

            $districtStationInsert->execute([
                (int) $district['id'],
                $stationName,
                $lat,
                $lng
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Upazilas
    |--------------------------------------------------------------------------
    */

    $upazilaInsert =
        $pdo->prepare(
            "
            INSERT INTO upazilas
                (
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
                    ?
                )

            ON DUPLICATE KEY UPDATE

                name =
                    VALUES(name),

                bn_name =
                    VALUES(bn_name),

                latitude =
                    VALUES(latitude),

                longitude =
                    VALUES(longitude)
            "
        );


    foreach (
        $upazilas as $row
    ) {

        $name =
            trim(
                (string)
                (
                    $row['name']
                    ?? ''
                )
            );

        $bnName =
            trim(
                (string)
                (
                    $row['bn_name']
                    ?? ''
                )
            );


        $sourceDistrictId =
            (int)
            (
                $row['district_id']
                ?? 0
            );


        $lat =
            isset(
                $row['lat']
            )
                ? (float)
                    $row['lat']
                : null;


        $lng =
            isset(
                $row['lng']
            )
                ? (float)
                    $row['lng']
                : null;


        if (
            $name === '' ||
            $sourceDistrictId <= 0
        ) {
            continue;
        }


        $districtStmt =
            $pdo->prepare(
                "
                SELECT id

                FROM districts

                WHERE source_id = ?

                LIMIT 1
                "
            );

        $districtStmt->execute([
            $sourceDistrictId
        ]);

        $district =
            $districtStmt->fetch();


        if (!$district) {
            continue;
        }


        $slug =
            slugify(
                $name
            );


        $upazilaInsert->execute([

            (int) ($row['id'] ?? 0),

            (int)
            $district['id'],

            $name,

            $bnName !== ''
                ? $bnName
                : null,

            $slug,

            $lat,

            $lng
        ]);
    }

    $stationInsert =
        $pdo->prepare(
            "
            INSERT INTO police_stations
                (
                    district_id,
                    name,
                    latitude,
                    longitude
                )

            VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )

            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude)
            "
        );


    if (
        $policeStations
    ) {

        foreach (
            $policeStations as $row
        ) {

            $name =
                trim(
                    (string)
                    (
                        $row['name']
                        ?? ''
                    )
                );

            $districtName =
                trim(
                    (string)
                    (
                        $row['district_name']
                        ?? ''
                    )
                );

            if (
                $name === ''
            ) {
                continue;
            }

            $districtId = null;

            if (
                $districtName !== ''
            ) {

                $districtStmt =
                    $pdo->prepare(
                        "
                        SELECT id

                        FROM districts

                        WHERE name = ?

                        LIMIT 1
                        "
                    );

                $districtStmt->execute([
                    $districtName
                ]);

                $district =
                    $districtStmt->fetch();

                if ($district) {
                    $districtId =
                        (int)
                        $district['id'];
                }
            }

            if (
                $districtId === null
            ) {
                continue;
            }

            $lat =
                isset(
                    $row['lat']
                )
                    ? (float) $row['lat']
                    : null;

            $lng =
                isset(
                    $row['lng']
                )
                    ? (float) $row['lng']
                    : null;

            $stationInsert->execute([
                $districtId,
                $name,
                $lat,
                $lng
            ]);
        }

    } else {

        $upazilaStations =
            $pdo->query(
                "
                SELECT
                    district_id,
                    name,
                    latitude,
                    longitude

                FROM upazilas
                "
            );

        foreach (
            $upazilaStations as $upazilaStation
        ) {
            $stationInsert->execute([
                (int) $upazilaStation['district_id'],
                (string) $upazilaStation['name'],
                $upazilaStation['latitude'] !== null
                    ? (float) $upazilaStation['latitude']
                    : null,
                $upazilaStation['longitude'] !== null
                    ? (float) $upazilaStation['longitude']
                    : null
            ]);
        }

    }


    $locationUpdate =
        $pdo->prepare(
            "
            UPDATE locations l

            SET l.police_station_id = (
                SELECT ps.id
                FROM police_stations ps
                WHERE ps.latitude IS NOT NULL
                  AND ps.longitude IS NOT NULL
                ORDER BY (
                    6371000 * 2 * ASIN(
                        SQRT(
                            POWER(SIN(RADIANS(ps.latitude - l.latitude) / 2), 2)
                            + COS(RADIANS(ps.latitude))
                            * COS(RADIANS(l.latitude))
                            * POWER(SIN(RADIANS(ps.longitude - l.longitude) / 2), 2)
                        )
                    )
                ) ASC
                LIMIT 1
            )

            WHERE l.police_station_id IS NULL
            "
        );

    $locationUpdate->execute();


    $pdo->commit();


    echo "\nImport completed successfully.\n";

} catch (
    Throwable $e
) {

    if (
        $pdo->inTransaction()
    ) {

        $pdo->rollBack();
    }


    echo "\nImport failed:\n";

    echo $e->getMessage();

}