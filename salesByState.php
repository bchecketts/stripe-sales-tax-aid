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
while ($row = fgetcsv($fh, 4096, "\t")) {
    $zip    = trim($row[1]);
    $state  = trim($row[3]);
    $abbv   = trim($row[4]);
    $zipToState[$zip] = $abbv;

    if ($state) {
        $stateAbbreviations[strtoupper($state)] = $abbv;
        $stateNames[$abbv] = $state;
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
        // These were out of the country
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
        debug(implode(",", $data));

        $counts['nonTaxableCount']++;
        $counts['nonTaxableRevenue'] += $data['Amount'];
        continue;
    }

    $counts['taxableCount']++;
    $counts['taxableRevenue'] += $data['Amount'];

    $location = null;

    if (!empty($data['Card Address Country']) && 'united states' != strtolower($data['Card Address Country'])) {
        debug("NON-US card address country: {$data['Card Address Country']}");
        $location = 'NON-US';
        // continue;
    }

    if (!empty($data['Card Issue Country']) && 'us' != strtolower($data['Card Issue Country'])) {
        debug("NON-US card issue country: {$data['Card Issue Country']}");
        $location = 'NON-US';
        // continue;
    }

    if (!empty($data['Shipping Address Country']) && !in_array($data['Shipping Address Country'], ['United States', 'United States of America', 'US', 'USA'])) {
        debug("NON-US shipping address country: {$data['Shipping Address Country']}");
        $location = 'NON-US';
        // continue;
    }

    if (!$location) {
        $zip = null;
        if (preg_match('#^([0-9]{5})\-[0-9]{4}#', $zip, $matches)) {
            // Change zip+4 to just 5-digit zip code
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

    if (!isset($byLocation[$location])) {
        $byLocation[$location] = [
            'count'     => 0,
            'revenue'   => 0,
        ];
    }

    if ($data['Amount'] > 0) {
        $byLocation[$location]['count']++;
        $byLocation[$location]['revenue'] += $data['Amount'];
    }

    $data['Location'] = $location;
    debug(implode(",", $data));
}

$byLocation['KNOWN'] = [
    'count' => 0,
    'revenue'   => 0,
];

foreach ($byLocation as $location => $values) {
    $stateName = $stateNames[$location] ?? null;
    // echo "{$location}  \t{$stateName}     {$values['count']}          {$values['revenue']}\n";

    if ('UNKNOWN' != $location) {
        $byLocation['KNOWN']['count'] += $values['count'];
        $byLocation['KNOWN']['revenue'] += $values['revenue'];
    }
}

echo "Total Transactions: {$counts['transactionsInPeriod']}      Revenue: {$counts['revenueInPeriod']}\n\n";

echo "Taxable:          {$counts['taxableCount']}                   Revenue: {$counts['taxableRevenue']}\n";
echo "Non-Taxable:      {$counts['nonTaxableCount']}                 Revenue: {$counts['nonTaxableRevenue']}\n";
echo "\n\n";


$pctCount = $byLocation['KNOWN']['count'] / $counts['transactionsInPeriod'] * 100;
$pctRevenue = $byLocation['KNOWN']['revenue'] / $counts['revenueInPeriod'] * 100;
echo "Known Location: Transaction: {$byLocation['KNOWN']['count']} (".number_format($pctCount, 2)."%)      Revenue: \${$byLocation['KNOWN']['revenue']} (".number_format($pctRevenue, 2)."%)\n";

$pctCount = $byLocation['UNKNOWN']['count'] / $counts['transactionsInPeriod'] * 100;
$pctRevenue = $byLocation['UNKNOWN']['revenue'] / $counts['revenueInPeriod'] * 100;
echo "UNKnown Location: Transaction: {$byLocation['UNKNOWN']['count']} (".number_format($pctCount, 2)."%)      Revenue: \${$byLocation['UNKNOWN']['revenue']} (".number_format($pctRevenue, 2)."%)\n";

echo "\n\n";

$stateNames['NON-US'] = 'NON-US';
    printf("%-8s  %-20s     %7s     %11s     %7s   %11s     %7s   %11s\n",
        'State', 'State', '# Known', '$ Known', '# Inferred', '$ Inferred', '# Total', '$ Total');

$csv = '';
foreach ($stateNames as $abbv => $stateName) {
    if ('UNKNOWN' == $abbv) continue;

    $values = $byLocation[$abbv];

    $pctByCount         = $byLocation[$abbv]['count']   / $byLocation['KNOWN']['count'];
    $pctByRevenue       = $byLocation[$abbv]['revenue'] / $byLocation['KNOWN']['revenue'];

    $inferredCount      = $byLocation['UNKNOWN']['count']   * $pctByCount;
    // $inferredRevenue    = $byLocation['UNKNOWN']['revenue'] * $pctByRevenue;
    $inferredRevenue    = $byLocation['UNKNOWN']['revenue'] * $pctByCount;

    $combinedCount      = $values['count']      + $inferredCount;
    $combinedRevenue    = $values['revenue']    + $inferredRevenue;

    // echo "{$abbv}\t{$stateName}         {$values['count']}      \${$values['revenue']}      {$inferredCount} \t{$inferredRevenue\n";
    // printf("%-8s  %-20s     %7u       %9.2f       %7u     %9.2f       %7u     %9.2f   (%5.2f%% / %5.2f%%)\n",
    //     $abbv, $stateName, $values['count'], $values['revenue'], $inferredCount, $inferredRevenue, $combinedCount, $combinedRevenue, $pctByCount * 100, $pctByRevenue * 100);
    printf("%-8s  %-20s     %7u       %9.2f       %7u     %9.2f       %7u     %9.2f     (%5.2f%%)\n",
        $abbv, $stateName, $values['count'], $values['revenue'], $inferredCount, $inferredRevenue, $combinedCount, $combinedRevenue, $pctByCount * 100);

    $csv .= implode(',', [
        $abbv,
        $stateName,
        $values['count'],
        $values['revenue'],
        $inferredCount,
        $inferredRevenue,
        $combinedCount,
        $combinedRevenue,
        number_format($pctByCount * 100, 2),
    ])."\n";
}

echo "\n\n{$csv}\n";
