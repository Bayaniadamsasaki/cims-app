import StatusBadge from "./StatusBadge";
import WidgetCard from "./WidgetCard";

/**
 * Widget list status perangkat (§6C): tabel tanpa border vertikal, pemisah baris
 * berupa garis bawah tipis, status memakai titik warna + teks.
 */
export default function DeviceStatusList({ devices = [], action }) {
    return (
        <WidgetCard
            title="Device Status"
            subtitle="Perangkat inti yang dipantau berkala"
            action={action}
            bodyClassName="mt-5 pb-2"
        >
            {devices.length === 0 ? (
                <p className="px-6 pb-4 text-sm text-slate-500">Belum ada perangkat yang dipantau.</p>
            ) : (
                <table className="w-full text-left">
                    <caption className="sr-only">Status perangkat jaringan inti beserta uptime</caption>
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th scope="col" className="px-6 pb-2 text-xs font-medium text-slate-500">
                                Perangkat
                            </th>
                            <th
                                scope="col"
                                className="hidden px-6 pb-2 text-xs font-medium text-slate-500 sm:table-cell"
                            >
                                Uptime
                            </th>
                            <th scope="col" className="px-6 pb-2 text-xs font-medium text-slate-500">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {devices.map((device) => (
                            <tr key={device.id} className="border-b border-slate-100 last:border-0">
                                <td className="px-6 py-3.5">
                                    <div className="text-sm font-medium text-slate-900">{device.name}</div>
                                    <div className="mt-0.5 text-xs text-slate-500">
                                        {device.location} · <span className="font-mono">{device.ip}</span>
                                    </div>
                                </td>
                                <td className="hidden px-6 py-3.5 text-sm tabular-nums text-slate-500 sm:table-cell">
                                    {device.uptime}
                                </td>
                                <td className="px-6 py-3.5">
                                    <StatusBadge status={device.status} variant="plain" />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </WidgetCard>
    );
}
