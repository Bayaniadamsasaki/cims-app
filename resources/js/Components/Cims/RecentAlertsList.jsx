import StatusBadge from "./StatusBadge";
import WidgetCard from "./WidgetCard";
import { statusOf } from "./theme";
import { IconCheckCircle, IconMaintenance, IconWarning } from "./icons";

const SEVERITY_ICON = {
    offline: IconWarning,
    warning: IconWarning,
    maintenance: IconMaintenance,
    online: IconCheckCircle,
};

/**
 * Widget daftar alert terbaru (§6C). Tiap baris dipisah garis bawah tipis dan
 * memakai ikon + badge status agar tingkat keparahan tidak hanya lewat warna.
 */
export default function RecentAlertsList({ alerts = [], action }) {
    return (
        <WidgetCard
            title="Recent Alerts"
            subtitle="Kejadian 24 jam terakhir"
            action={action}
            bodyClassName="mt-4 pb-2"
        >
            {alerts.length === 0 ? (
                <p className="px-6 pb-4 text-sm text-slate-500">Tidak ada alert aktif. Semua segmen normal.</p>
            ) : (
                <ul>
                    {alerts.map((alert) => {
                        const tone = statusOf(alert.severity);
                        const Icon = SEVERITY_ICON[alert.severity] ?? IconWarning;

                        return (
                            <li
                                key={alert.id}
                                className="flex items-start gap-3 border-b border-slate-100 px-6 py-3.5 last:border-0"
                            >
                                <span
                                    className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${tone.soft}`}
                                >
                                    <Icon className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-slate-900">{alert.message}</p>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        {alert.device} · <time dateTime={alert.at}>{alert.ago}</time>
                                    </p>
                                </div>
                                <StatusBadge status={alert.severity} label={alert.severityLabel} />
                            </li>
                        );
                    })}
                </ul>
            )}
        </WidgetCard>
    );
}
