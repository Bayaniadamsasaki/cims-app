import zipfile
import xml.etree.ElementTree as ET
import json
import sys
import os

def parse_excel(file_path):
    if not os.path.exists(file_path):
        return {"error": f"File not found: {file_path}"}
        
    z = zipfile.ZipFile(file_path)
    
    shared_strings = []
    if 'xl/sharedStrings.xml' in z.namelist():
        tree = ET.fromstring(z.read('xl/sharedStrings.xml'))
        ns = {'main': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        for si in tree.findall('main:si', ns):
            texts = [t.text for t in si.findall('.//main:t', ns) if t.text is not None]
            shared_strings.append(''.join(texts))
            
    wb_tree = ET.fromstring(z.read('xl/workbook.xml'))
    ns_wb = {'main': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    
    rels_tree = ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))
    rels = {}
    for rel in rels_tree.findall('{http://schemas.openxmlformats.org/package/2006/relationships}Relationship'):
        rels[rel.attrib['Id']] = rel.attrib['Target']
        
    sheets = {}
    for s in wb_tree.find('main:sheets', ns_wb).findall('main:sheet', ns_wb):
        name = s.attrib['name']
        r_id = s.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']
        path = 'xl/' + rels[r_id]
        sheets[name] = path
        
    ns_s = {'main': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    
    result = {
        "gedung_ruangan": [],
        "routers": []
    }
    
    # 1. Parse GEDUNG & RUANGAN
    if 'GEDUNG & RUANGAN' in sheets:
        s_tree = ET.fromstring(z.read(sheets['GEDUNG & RUANGAN']))
        sheet_data = s_tree.find('main:sheetData', ns_s)
        if sheet_data is not None:
            for row in sheet_data.findall('main:row', ns_s):
                r_num = int(row.attrib.get('r', '0'))
                if r_num < 2: continue
                cols = get_row_cols(row, shared_strings, ns_s)
                if cols.get('A'):
                    result["gedung_ruangan"].append({
                        "kode_gedung": cols.get('A'),
                        "kode_lantai": cols.get('B'),
                        "kode_ruangan": cols.get('C'),
                        "kode_tempat": cols.get('D'),
                        "nama_ruangan": cols.get('E')
                    })
                    
    # 2. Parse Router
    if 'Router' in sheets:
        s_tree = ET.fromstring(z.read(sheets['Router']))
        sheet_data = s_tree.find('main:sheetData', ns_s)
        if sheet_data is not None:
            current_device = None
            for row in sheet_data.findall('main:row', ns_s):
                r_num = int(row.attrib.get('r', '0'))
                if r_num < 4: continue
                cols = get_row_cols(row, shared_strings, ns_s)
                
                if 'A' in cols or ('D' in cols and cols['D'] in ['Router', 'Switch', 'Access Point']):
                    if current_device:
                        result["routers"].append(current_device)
                    current_device = {
                        "no": cols.get('A'),
                        "hostname": cols.get('B'),
                        "posisi": cols.get('C'),
                        "jenis": cols.get('D', 'Router'),
                        "merek": cols.get('E', 'MikroTik'),
                        "board": cols.get('F', 'Device'),
                        "bandwidth": cols.get('G'),
                        "firmware": cols.get('H'),
                        "serial_number": cols.get('I'),
                        "username": cols.get('J'),
                        "password": cols.get('K'),
                        "interfaces": []
                    }
                    
                if current_device and ('L' in cols or 'M' in cols or 'O' in cols):
                    current_device["interfaces"].append({
                        "name": cols.get('L', 'ether'),
                        "mac": cols.get('M'),
                        "bridge": cols.get('N'),
                        "ip": cols.get('O'),
                        "prefix": cols.get('P'),
                        "type": cols.get('Q', 'Ethernet'),
                        "status": cols.get('S', 'Aktif'),
                        "alokasi": cols.get('T'),
                        "detail": cols.get('U')
                    })
                    
            if current_device:
                result["routers"].append(current_device)
                
    return result

def get_row_cols(row, shared_strings, ns_s):
    cols = {}
    for c in row.findall('main:c', ns_s):
        r_cell = c.attrib.get('r', '')
        col_letter = ''.join([ch for ch in r_cell if ch.isalpha()])
        t_type = c.attrib.get('t', '')
        v_elem = c.find('main:v', ns_s)
        val = v_elem.text if v_elem is not None else ''
        if t_type == 's' and val.isdigit():
            idx = int(val)
            if idx < len(shared_strings):
                val = shared_strings[idx]
        if val.strip():
            cols[col_letter] = val.strip()
    return cols

if __name__ == '__main__':
    file_path = sys.argv[1] if len(sys.argv) > 1 else 'Docs/Inventaris Jaringan UBG.xlsx'
    data = parse_excel(file_path)
    print(json.dumps(data, indent=2))
