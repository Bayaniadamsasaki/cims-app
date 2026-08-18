import { statusOf } from "./theme";

/**
 * Badge status: titik warna + teks (§6C).
 * Teks selalu dirender agar status tetap terbaca tanpa mengandalkan warna.
 *
 * @param {"online"|"offline"|"warning"|"maintenance"} status
 * @param {"chip"|"plain"} variant chip = berlatar tint, plain = inline pada tabel
 */
export default function StatusBadge({ status, label, variant = "chip" }) {
    const tone = statusOf(status);
    const text = label ?? tone.label;

    if (variant === "plain") {
        return (
            <span className="inline-flex items-center gap-2 text-xs font-medium">
                <span className={`h-2 w-2 shrink-0 rounded-full ${tone.dot}`} aria-hidden="true" />
                <span className={tone.text}>{text}</span>
            </span>
        );
    }

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${tone.chip}`}
        >
            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${tone.dot}`} aria-hidden="true" />
            {text}
        </span>
    );
}
