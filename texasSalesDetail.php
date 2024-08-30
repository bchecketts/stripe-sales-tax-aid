<?php


function debug($msg)
{
    if (in_array('--debug', $GLOBALS['argv'])) {
        echo "{$msg}\n";
    }
}

// Read in zip code database
$fh = fopen('zipcodes.txt' , 'r');
$zipToState = [];
$stateAbbreviations = [];
$zipToCity = [];
while ($row = fgetcsv($fh, 4096, "\t")) {
    $zip    = trim($row[1]);
    $city   = trim($row[2]);
    $state  = trim($row[3]);
    $abbv   = trim($row[4]);

    $zipToState[$zip]   = $abbv;
    $zipToCity[$zip]    = $city;

    if ($state) {
        $stateAbbreviations[strtoupper($state)] = $abbv;
        $stateNames[$abbv] = $state;
    }
}
fclose($fh);

$taxRatesByCity = [];
$fh = fopen('texas-city-rates.csv', 'r');
$header = [];
while ($row = fgetcsv($fh, 4096, ",")) {
    // Get rid of extra spaces for some reason
    foreach ($row as $i => $v) {
        $row[$i] = trim($v);
    }

    if (empty($header)) {
        $header = $row;
        // I had to add a bogus column to the header for some reason....
        array_shift($header);
        continue;
    }

    $data = array_combine($header, $row);
    $city = strtolower($data['CityName']);

    if (isset($taxRatesByCity[$city])) {
        // Do nothing
    } else {
        $taxRatesByCity[$city] = $data;
    }
}
fclose($fh);


// A series of fixes for non-conforming data
$zipToState['10134'] = 'NY';
$zipToState['84009'] = 'UT';
$zipToState['40085'] = 'OK';
$zipToState['02620'] = 'MA';
$zipToState['MN']    = 'MN';
$zipToState['NY']    = 'NY';
$zipToState['33079'] = 'FL';
$zipToState['49418-1342'] = 'MI';

$zipToState['00000'] = 'UNKNOWN';
$zipToState['11111'] = 'UNKNOWN';
$zipToState['28014'] = 'UNKNOWN';
$zipToState['4730707'] = 'UNKNOWN';
$zipToState['25170'] = 'NON-US';
$zipToState['32587'] = 'NON-US';
$zipToState['00919'] = 'NON-US';
$zipToState['50200'] = 'NON-US';
$zipToState['41200'] = 'NON-US';
$zipToState['0910'] = 'NON-US';

$fixes = [
    'DOES NOT APPLY'        => 'UNKNOWN',
    '.'                     => 'UNKNOWN',
    'ARA'                   => 'UNKNOWN',
    'NSW'                   => 'UNKNOWN',

    'ONTARIO'               => 'NON-US',
    'ISRAEL'                => 'NON-US',
    'LA PAZ'                => 'NON-US',
    'VIC'                   => 'NON-US',
    'AB'                    => 'NON-US',
    'ON'                    => 'NON-US',
    'NB'                    => 'NON-US',
    'GERMANY'               => 'NON-US',
    'CYPRESS'               => 'NON-US',
    'KOWLOON'               => 'NON-US',
    'BERN'                  => 'NON-US',
    'TOKYO'                 => 'NON-US',
    'POLAND'                => 'NON-US',
    'LONDON'                => 'NON-US',
    'KAUNAS'                => 'NON-US',
    'MIRANDA'               => 'NON-US',
    'VICTORIA, AUSTRALIA'   => 'NON-US',
    'NUEVO LEON'            => 'NON-US',
    'SANTA BARBARA'         => 'NON-US',
    'BC'                    => 'NON-US',
    '54'                    => 'NON-US',

    'NY - NEW YORK'         => 'NY',
    'N.Y.'                  => 'NY',
    'DELAWER'               => 'DE',
    'FLLORIDA'              => 'FL',
    'ILLINOISE'             => 'IL',
    'INDIANA (IN)'          => 'IN',
    'MO - MISSOURI'         => 'MO',
    'MI - MICHIGAN'         => 'MI',
    'CA - CALIFORNIA'       => 'CA',
    'CA.'                   => 'CA',
    'PA.'                   => 'PA',
    'IL.'                   => 'IL',
    '`TX'                   => 'TX',
    'TEXAS (TX)'            => 'TX',
    'NEW JERSEY (NJ)'       => 'NJ',
    '07470'                 => 'NJ',
];

$counts = [
    'transactions'          => 0,
    'transactionsInPeriod'  => 0,

    'revenue'               => 0,
    'revenueInPeriod'       => 0,

    'nonTaxableCount'       => 0,
    'nonTaxableRevenue'     => 0,

    'taxableCount'       => 0,
    'taxableRevenue'     => 0,
];

$infile = 'your-stripe-transaction-export.csv';
$fh = fopen($infile, 'r');

$totalDue = 0;

$header = null;
while ($row = fgetcsv($fh, 8192)) {
    if (!$header) {
        $header = $row;
        continue;
    }

    $data = array_combine($header, $row);

    $taxable = true;

    if ('paid' != strtolower($data['Status'])) {
        continue;
    }

    if ('true' != strtolower($data['Captured'])) {
        continue;
    }

    if ('usd' != strtolower($data['Currency'])) {
        continue;
    }

    $counts['transactions']++;
    $counts['revenue'] += $data['Amount'];

    if (preg_match('#you-could-add-something-here#i',  $data['Description'])) {
        // If some of your transactions are not taxable, you can modify the regex above
        $taxable = false;
    }

    if (strtotime($data['Created date (UTC)']) < strtotime('2017-04-01')) {
        // $taxable = false;
        $data['Last'] = 'NOT IN PERIOD';
        debug(implode(",", $data));
    }

    if (strtotime($data['Created date (UTC)']) > strtotime('2017-12-31')) {
        // $taxable = false;
        $data['Last'] = 'NOT IN PERIOD';
        debug(implode(",", $data));
    }

    $counts['transactionsInPeriod']++;
    $counts['revenueInPeriod'] += $data['Amount'];

    if (!$taxable) {
        $data['Last'] = 'NOT TAXABLE';
        // echo implode(",", $data)."\n";

        $counts['nonTaxableCount']++;
        $counts['nonTaxableRevenue'] += $data['Amount'];
        // continue;
    }

    $counts['taxableCount']++;
    $counts['taxableRevenue'] += $data['Amount'];

    $location = null;

    if (!empty($data['Card Address Country']) && 'united states' != strtolower($data['Card Address Country'])) {
        // echo "NON-US card address country: {$data['Card Address Country']}\n";
        $location = 'NON-US';
        // continue;
    }

    if (!empty($data['Card Issue Country']) && 'us' != strtolower($data['Card Issue Country'])) {
        // echo "NON-US card issue country: {$data['Card Issue Country']}\n";
        $location = 'NON-US';
        // continue;
    }

    if (!empty($data['Shipping Address Country']) && !in_array($data['Shipping Address Country'], ['United States', 'United States of America', 'US', 'USA'])) {
        // echo "NON-US shipping address country: {$data['Shipping Address Country']}\n";
        $location = 'NON-US';
        // continue;
    }

    $zip = null;
    if (!$location) {
        if (preg_match('#^([0-9]{5})\-[0-9]{4}#', $zip, $matches)) {
            $zip = trim($matches[1]);
        }

        if (!empty($data['Shipping Address Postal Code'])) {
            $zip = trim($data['Shipping Address Postal Code']);
        } elseif ($data['Card Address Zip']) {
            $zip = trim($data['Card Address Zip']);
        }

        $state = null;
        if (!empty($data['Shipping Address State'])) {
            $state = trim($data['Shipping Address State']);
        } elseif ($data['Card Address State']) {
            $state = trim($data['Card Address State']);
        }

        $stateFromZip = null;
        if ($zip) {
            $zip = trim($zip);
            $stateFromZip = $zipToState[$zip] ?? null; // "no-zip-match: #{$zip}#";
        }

        $state = $state ?? $stateFromZip ?? null;
        $state = strtoupper($state);
        if (isset($stateAbbreviations[$state])) {
            $state = $stateAbbreviations[$state];
        }

        $location = $state;
    }

    if (isset($fixes[$location])) {
        $location = $fixes[$location];
    }

    if (!$location) {
        $location = 'UNKNOWN';
    }

    $data['Location'] = $location;
    // echo implode(",", $data)."\n";

    if ('TX' == $location) {
        $customerId = $data['Customer ID'];

        if (preg_match('#([0-9]{5})\-#', $zip, $matches)) {
            $zip = trim($matches[1]);
        }

        $city = trim(strtolower($zipToCity[$zip] ?? 'Unknown'));

        $cityRate   = $taxRatesByCity[$city]['CityTax']     ?? 0;
        $countyRate = $taxRatesByCity[$city]['CountyTax']   ?? 0;
        $spdRate    = $taxRatesByCity[$city]['SPDTax']      ?? 0;

        debug("City for {$zip} = {$city} / {$cityRate} / {$countyRate} / {$spdRate}");

        $taxableAmount = $taxable ? $data['Amount'] * 0.8 : 0;

        $tax = round($taxableAmount * 0.8 * (0.0625 + $cityRate + $countyRate + $spdRate), 2);

        $outputRow = [
            'customer'          => $customerId,
            'zip'               => $zip ?? 'Unknown',
            'city'              => $city,
            'reference'         => $data['id'],
            'date'              => date('Y-m-d', strtotime($data['Created date (UTC)'])),
            'invoice_amount'    => $data['Amount'],
            'taxable_amount'    => $taxableAmount,
            'description'       => $taxable ? 'Data Processing' : 'Internet Hosting',
            'state_tax'         => 'Y',
            'city_code'         => $taxRatesByCity[$city]['CityNo'] ?? '',
            'county_code'       => $taxRatesByCity[$city]['CountyNo'] ?? '',
            'mta_code'          => '',
            'spd_code'          => $taxRatesByCity[$city]['SPDNo'] ?? '',
            'total_tax'         => $tax,
        ];

        if ($taxable) {
            // "No zero taxable transactions need to be reported.  Only the Texas taxable sales should be reported for the lookback period"
            echo implode("\t", $outputRow)."\n";
            $totalDue += $tax;
        }
    }
}

echo "Total Due: ".number_format($totalDue, 2)."\n";
