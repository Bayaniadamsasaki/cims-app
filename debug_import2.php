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
    
    $summaryPath = $result['Ringkasan Perangkat'] ?? null;
    $interfacePath = $result['Interface'] ?? null;
    
    // Parse summary - this is what SingleDeviceAuditImport::parseSummarySheet does
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
    
    echo "Summary parsed OK\n";
    echo "  Board: " . ($summary['Board'] ?? 'MISSING') . "\n";
    echo "  IP: " . ($summary['Host / IP Router'] ?? 'MISSING') . "\n";
    echo "  SN: " . ($summary['Serial Number (SN)'] ?? 'MISSING') . "\n";
    echo "  Merek: " . ($summary['Merek'] ?? 'MISSING') . "\n";
    
    // Parse interfaces - this is what SingleDeviceAuditImport::parseInterfaceSheet does
    echo "\nInterface parsing:\n";
    if ($interfacePath) {
        $xml = $z->getFromName($interfacePath);
        $doc = new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        
        $skipHeader = false;
        $rowNum = 0;
        foreach ($doc->xpath('//m:sheetData/m:row') as $row) {
            $rowNum++;
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
            
            // Skip header row (where A is 'Nama Interface')
            if ($rowNum === 1 && ($cols['A'] ?? '') === 'Nama Interface') {
                $skipHeader = true;
                echo "  Row $rowNum: Header row skipped (A='Nama Interface')\n";
                continue;
            }
            
            if ($skipHeader) {
                $name = $cols['A'] ?? '';
                $type = $cols['B'] ?? '';
                $mac = $cols['C'] ?? '';
                $status = $cols['D'] ?? '';
                $ip = $cols['H'] ?? '';
                echo "  Row $rowNum: name='$name', type='$type', mac='$mac', status='$status', ip='$ip'\n";
            }
        }
    }
    
    $z->close();
}