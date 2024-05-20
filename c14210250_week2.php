<?php
require 'Predis/Predis/Autoload.php';
Predis\Autoloader::register();
use Predis\Client;
$redis = new Client();

function raw($redis)
{
    $headers = $redis->lrange('headers', 0, -1);
    $timestamps = $redis->executeRaw(['TS.RANGE', $headers[1], '-', '+']);
    $transHeader = [];
    foreach ($headers as $header) {
        $words = preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $header);

        if ($words[0] == '') {
            array_shift($words);
        }
        $formattedHeader = implode(" ", $words);
        array_push($transHeader, $formattedHeader);
    }
    echo "<h2 style='text-align:center; margin-top:10px'>Global Land Temperature</h2>";
    echo "<div class='table-wrapper'>
        <table class='table table-bordered'>
            <thead class='table-light'>
                <tr>";
    foreach ($transHeader as $head) {
        echo "<th scope='col' style='vertical-align: middle; text-align: center;'>" . $head . "</th>";
    }
    echo "</tr>
        </thead>
        <tbody>";

    for ($i = 0; $i < count($timestamps); $i++) {
        $dt = $timestamps[$i][0];

        $fields = [];
        for ($j = 1; $j < count($headers); $j++) {
            $header = $headers[$j];
            $value = $redis->executeRaw(['TS.RANGE', $header, $dt, $dt]);
            $fields[$header] = $value[0][1];
        }

        echo "<tr style='text-align: center'>";
        echo "<td>" . date('Y-m-d', $dt / 1000) . "</td>";
        foreach ($fields as $value) {
            echo "<td>" . number_format((float)(string)$value, 3) . "</td>";
        }
        echo "</tr>";
    }

    echo "</tbody></table></div>";
}
function agr($redis)
{
    $headers = $redis->lrange('headers', 0, -1);
    $timestamps = $redis->tsrange($headers[1] . '_aggregated', '-', '+');
    $transHeader = [];
    foreach ($headers as $header) {
        $words = preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $header);

        if ($words[0] == '') {
            array_shift($words);
        }
        $formattedHeader = implode(" ", $words);
        array_push($transHeader, $formattedHeader);
    }
    echo "<h2 style='text-align:center; margin-top:10px'>Global Land Temperature</h2>";
    echo "<div class='table-wrapper'>
        <table class='table table-bordered'>
            <thead class='table-light'>
                <tr>";
    foreach ($transHeader as $head) {
        echo "<th scope='col' style='vertical-align: middle; text-align: center;'>" . $head . "</th>";
    }
    echo "</tr>
        </thead>
        <tbody>";
    for ($i = 0; $i < count($timestamps); $i++) {
        $dt = $timestamps[$i][0];

        $fields = [];
        for ($j = 1; $j < count($headers); $j++) {
            $header = $headers[$j] . '_aggregated';
            $value = $redis->executeRaw(['TS.RANGE', $header, $dt, $dt]);
            $fields[$header] = $value[0][1];
        }

        echo "<tr style='text-align: center'>";
        echo "<td>" . date('Y-m-d', $dt / 1000) . "</td>";
        foreach ($fields as $value) {
            echo "<td>" . number_format((float)(string)$value, 3) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Global Land</title>
    <style>
        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            table-layout: fixed;
            width: 100%;
        }

        th,
        td {
            word-wrap: break-word;
            white-space: normal;
        }

        th {
            text-align: center;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="col-4 mt-3">
            <form method="POST" enctype="multipart/form-data">
                <div class="container">
                    <div class="row">
                        <label for="formFile" class="form-label">Select CSV file to upload</label>
                        <div class="col-8">
                            <input class="form-control" type="file" name="uploadedFile" id="formFile">
                        </div>
                        <div class="col-4">
                            <button class="btn btn-secondary" name="upload">UPLOAD</button>
                        </div>
                    </div>
                    <div class="container" style="margin-top: 20px; text-align: left">
                        <button class="btn btn-secondary" name="Raw">Raw</button>
                        <button class="btn btn-secondary" name="Aggr">AGR</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        //Upload
        if (isset($_POST['upload'])) {
            $csvRows = [];
            $headers = [];
            $redis->executeRaw(['DEL', 'headers']);
            if ($_FILES['uploadedFile']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['uploadedFile']['tmp_name'];

                if (($handle = fopen($file, 'r')) !== false) {
                    if (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        $headers = $data;
                    }

                    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        $csvRows[] = array_combine($headers, $data);
                    }
                    fclose($handle);
                }
            }
            // Rule Aggregate
            $year = 60 * 60 * 24 * 365.2425 * 1000;
            $mode = '';
            for ($i = 0; $i < count($headers); $i++) {
                if (strpos(strtolower($headers[$i]), 'average')) {
                    $mode = 'avg';
                }
                if (strpos(strtolower($headers[$i]), 'max')) {
                    $mode = 'max';
                }
                if (strpos(strtolower($headers[$i]), 'min')) {
                    $mode = 'min';
                }
                $redis->executeRaw(['DEL', $headers[$i]]);
                $redis->executeRaw(['DEL', $headers[$i] . '_aggregated']);
                $redis->executeRaw(['TS.CREATE', $headers[$i]]);
                $redis->executeRaw(['TS.CREATE', $headers[$i] . '_aggregated']);
                $redis->executeRaw(['TS.CREATERULE', $headers[$i], $headers[$i] . '_aggregated', 'AGGREGATION', $mode, $year]);
            }

            // Simpan Headers
            for ($i = 0; $i < count($headers); $i++) {
                $redis->rpush('headers', $headers[$i]);
            }

            $arr = [];
            $headerCount = count($headers);

            // Masukin data ke database
            for ($i = 1; $i < count($csvRows); $i++) {
                $dt = abs(strtotime($csvRows[$i][$headers[0]])) * 1000;

                for ($j = 1; $j < $headerCount; $j++) {
                    $header = $headers[$j];
                    $value = $csvRows[$i][$header];

                    $redis->executeRaw(['TS.ADD', $header, $dt, $value]);
                }
            }
        }
        if (isset($_POST['Raw'])) {
            if ($redis->llen('headers') == 0) {
                echo "<script>alert('Belum ada data yang dimasukan')</script>";
            } else {
                raw($redis);
            }
        }
        if (isset($_POST['Aggr'])) {
            if ($redis->llen('headers') == 0) {
                echo "<script>alert('Belum ada data yang dimasukan')</script>";
            } else {
                agr($redis);
            }
        }
        ?>
    </div>
</body>

</html>