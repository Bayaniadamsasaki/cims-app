import { useEffect, useState } from 'react';
import * as XLSX from 'xlsx';

export default function ExcelViewer({ fileUrl }) {
  const [data, setData] = useState([]);
  const [sheetNames, setSheetNames] = useState([]);
  const [activeSheet, setActiveSheet] = useState('');

  useEffect(() => {
    if (!fileUrl) return;
    fetch(fileUrl)
      .then(res => res.arrayBuffer())
      .then(ab => {
        const workbook = XLSX.read(ab, { type: 'array' });
        const names = workbook.SheetNames;
        setSheetNames(names);
        setActiveSheet(names[0]);
        const ws = workbook.Sheets[names[0]];
        const json = XLSX.utils.sheet_to_json(ws, { header: 1 });
        setData(json);
      });
  }, [fileUrl]);



  const handleSheetChange = (name) => {
    // For simplicity, reload file and switch sheet
    fetch(fileUrl)
      .then(res => res.arrayBuffer())
      .then(ab => {
        const workbook = XLSX.read(ab, { type: 'array' });
        const ws = workbook.Sheets[name];
        const json = XLSX.utils.sheet_to_json(ws, { header: 1 });
        setData(json);
        setActiveSheet(name);
      });
  };

  const handleSave = () => {
    // Export edited data back to Excel
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    XLSX.utils.book_append_sheet(wb, ws, activeSheet);
    const out = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
    const blob = new Blob([out], { type: 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'edited.xlsx';
    a.click();
  };

  return (
    <div className="border rounded">
      <div className="flex gap-2 p-2 bg-gray-100 flex-wrap">
        {sheetNames.map(name => (
          <button
            key={name}
            onClick={() => handleSheetChange(name)}
            className={`px-3 py-1 rounded ${activeSheet===name ? 'bg-blue-600 text-white' : 'bg-white'}`}
          >
            {name}
          </button>
        ))}
        <button onClick={handleSave} className="ml-auto px-3 py-1 bg-green-600 text-white rounded">Download</button>
      </div>
      <div className="overflow-auto" style={{ height: '60vh' }}>
        <table className="w-full text-sm border">
          <tbody>
            {data.map((row, i) => (
              <tr key={i}>
                {row.map((cell, j) => (
                  <td key={j} className="border px-2 py-1">{cell ?? ''}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
