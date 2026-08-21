<?php
require 'vendor/autoload.php';

$z = new ZipArchive();
$file = 'Docs/Mikrotik_RB450Gx4_Astinet_GUL01R19.xlsx';

if ($z->open($file) === true) {
    $wbXml = $z->getFromName('xl/workbook.xml');
    $wb = new SimpleXMLElement($wbXml);
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    
    $sheetNames = [];
    foreach ($wb->xpath('//m:sheet') as $sheet) {
        $sheetNames[] = (string)$sheet['name'];
    }
    
    $relsXml = $z->getFromName('xl/_rels/workbook.xml.rels');
    $rels = new SimpleXMLElement($relsXml);
    $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relMap = [];
    foreach ($rels->xpath('//r:Relationship') as $rel) {
        $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }
    
    $result = [];
    foreach ($wb->xpath('//m:sheet') as $sheet) {
        $name = (string)$sheet['name'];
        $rId  = (string)$sheet->attributes($r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rId] ?? null;
        if ($target) {
            if (str_starts_with($target, '/')) {
                $result[$name] = ltrim($target, '/');
            } elseif (!str_starts_with($target, 'xl/')) {
                $result[$name] = 'xl/' . $target;
            } else {
                $result[$name] = $target;
            }
        }
    }
    
    // Test canHandle
    echo "canHandle check:\n";
    echo "  Has 'Ringkasan Perangkat': " . (in_array('Ringkasan Perangkat', $sheetNames) ? 'YES' : 'NO') . "\n";
    echo "  Has 'Interface': " . (in_array('Interface', $sheetNames) ? 'YES' : 'NO') . "\n";
    echo "  Overall: " . (in_array('Ringkasan Perangkat', $sheetNames) && in_array('Interface', $sheetNames) ? 'YES - will use SingleDeviceAuditImport' : 'NO') . "\n\n";
    
    // Now simulate what import does
    $summaryPath = $result['Ringkasan Perangkat'] ?? null;
    $interfacePath = $result['Interface'] ?? null;
    
    // Parse summary
    $xml = $z->getFromName($summaryPath);
    $rows = [];
    if ($xml) {
        $doc = new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($doc->xpath('//m:sheetData/m:row') as $row) {
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cols = [];
            foreach ($row->xpath('m:c') as $cell) {
                $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $ref = (string)$cell['r'];
                $colLetter = preg_replace('/[0-9]/', '', $ref);
                $type = (string)$cell['t'];
                $isElem = $cell->xpath('m:is/m:t');
                if (!empty($isElem)) {
                    $val = (string)$isElem[0];
                } else {
                    $vElem = $cell->xpath('m:v');
                    $val = !empty($vElem) ? (string)$vElem[0] : '';
                }
                if (trim($val) !== '') {
                    $cols[$colLetter] = trim($val);
                }
            }
            if (!empty($cols)) {
                $rows[] = $cols;
            }
        }
    }
    
    $summary = [];
    foreach ($rows as $row) {
        $key = $row['A'] ?? null;
        $val = $row['B'] ?? null;
        if ($key && $val && $key !== 'Parameter') {
            $summary[$key] = $val;
        }
    }
    
    echo "Parsed summary:\n";
    foreach ($summary as $k => $v) {
        echo "  $k => $v\n";
    }
    
    // Build device name
    $board = $summary['Board'] ?? '';
    $ip = $summary['Host / IP Router'] ?? '';
    if ($board && $ip) {
        $deviceName = "MikroTik_{$board}_{$ip}";
    } else {
        $basename = pathinfo($file, PATHINFO_FILENAME);
        $deviceName = str_replace(['_', '-'], ' ', $basename);
    }
    echo "\nGenerated device name: $deviceName\n";
    
    // Check match key
    $serialNum = $summary['Serial Number (SN)'] ?? null;
    $ipAddress = $summary['Host / IP Router'] ?? null;
    
    echo "\nMatch key analysis:\n";
    if ($serialNum) {
        echo "  Using serial_number: $serialNum\n";
    } elseif ($ipAddress) {
        echo "  Using ip_address: $ipAddress\n";
    } else {
        echo "  Using device name: $deviceName\n";
    }
    
    // Parse interfaces (skip header)
    echo "\nInterface data (after skipping header):\n";
    if ($interfacePath) {
        $xml = $z->getFromName($interfacePath);
        $doc = new SimpleXMLElement($interfacePath ?: '');
        if ($xml) {
            $doc = new SimpleXMLElement($xml);
            $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rowIdx = 0;
            foreach ($doc->xpath('//m:sheetData/m:row') as $row) {
                $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cols = [];
                foreach ($row->xpath('m:c') as $cell) {
                    $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $ref = (string)$cell['r'];
                    $colLetter = preg_replace('/[0-9]/', '', $ref);
                    $isElem = $cell->xpath('m:is/m:t');
                    if (!empty($isElem)) {
                        $val = (string)$isElem[0];
                    } else {
                        $vElem = $cell->xpath('m:v');
                        $val = !empty($vElem) ? trim((string)$vElem[0]) : '';
                    }
                    if (trim($val) !== '') {
                        $cols[$colLetter] = trim($val);
                    }
                }
                if (!empty($cols) && ($cols['A'] ?? '') !== 'Nama Interface') {
                    $name = $cols['A'] ?? '';
                    $type = $cols['B'] ?? '';
                    $mac = $cols['C'] ?? '';
                    $status = $cols['D'] ?? '';
                    $ipAddr = $cols['H'] ?? '';
                    echo "  Interface: $name, Type: $type, MAC: $mac, Status: $status, IP: $ipAddr\n";
                    $rowIdx++;
                }
            }
        }
    }
    
    $z->close();
}