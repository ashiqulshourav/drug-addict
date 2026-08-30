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

    } elseif (
        $name === 'districts'
    ) {

        $districts =
            $table['data'];

    } elseif (
        $name === 'upazilas'
    ) {

        $upazilas =
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
                    name,
                    slug
                )

            VALUES
                (
                    ?,
                    ?
                )

            ON DUPLICATE KEY UPDATE
                name = VALUES(name)
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


        /*
         * Use English name as canonical name.
         */

        $slug =
            slugify(
                $name
            );


        $divisionInsert->execute([
            $name,
            $slug
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
                    division_id,
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
                division_id =
                    VALUES(division_id),
                name =
                    VALUES(name)
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


        /*
         * Source district_id/Division relation
         * is used to locate the parent.
         *
         * We first try source division_id.
         */

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


        /*
         * If source IDs don't match our IDs,
         * fall back to normalized name.
         */

        if (!$division) {

            /*
             * Skip instead of creating
             * a wrong relationship.
             */

            continue;
        }


        $slug =
            slugify(
                $name
            );


        $districtInsert->execute([
            (int)
            $division['id'],

            $name,

            $slug
        ]);
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


        /*
         * Find our district using
         * the source district ID.
         *
         * Our districts table currently
         * does not retain source ID, so
         * name-based matching is safer.
         */

        $districtName =
            trim(
                (string)
                (
                    $row['district_name']
                    ?? ''
                )
            );


        /*
         * If JSON doesn't provide district_name,
         * we cannot safely assign it.
         */

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


        $slug =
            slugify(
                $name
            );


        $upazilaInsert->execute([

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