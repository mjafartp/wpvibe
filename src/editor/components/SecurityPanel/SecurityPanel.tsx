/**
 * SecurityPanel — Slide-over panel showing security scan results.
 *
 * Appears when the AI detects security vulnerabilities in the generated
 * theme code. Displays findings grouped by severity and offers a one-click
 * "Fix & Apply" or "Fix & Export" action.
 */

import { useEditorStore } from '@/editor/store/editorStore';
import type { SecurityFinding } from '@/editor/api/wordpress';

// ---------------------------------------------------------------------------
// Severity helpers
// ---------------------------------------------------------------------------

const SEVERITY_CONFIG = {
  critical: {
    label: 'Critical',
    badgeCls: 'vb-bg-red-100 vb-text-red-700 vb-border-red-200',
    dotCls: 'vb-bg-red-500',
  },
  high: {
    label: 'High',
    badgeCls: 'vb-bg-orange-100 vb-text-orange-700 vb-border-orange-200',
    dotCls: 'vb-bg-orange-500',
  },
  medium: {
    label: 'Medium',
    badgeCls: 'vb-bg-yellow-100 vb-text-yellow-700 vb-border-yellow-200',
    dotCls: 'vb-bg-yellow-500',
  },
  low: {
    label: 'Low',
    badgeCls: 'vb-bg-blue-100 vb-text-blue-700 vb-border-blue-200',
    dotCls: 'vb-bg-blue-500',
  },
} as const;

// ---------------------------------------------------------------------------
// SecurityPanel
// ---------------------------------------------------------------------------

export function SecurityPanel() {
  const scanResult = useEditorStore((s) => s.scanResult);
  const pendingAction = useEditorStore((s) => s.pendingAction);
  const isFixing = useEditorStore((s) => s.isFixing);
  const fixSecurityIssues = useEditorStore((s) => s.fixSecurityIssues);
  const dismissScanResult = useEditorStore((s) => s.dismissScanResult);
  const proceedWithoutFix = useEditorStore((s) => s.proceedWithoutFix);

  if (!scanResult || scanResult.safe) return null;

  const findings = scanResult.findings;

  // Count by severity.
  const counts = { critical: 0, high: 0, medium: 0, low: 0 };
  for (const f of findings) {
    if (f.severity in counts) {
      counts[f.severity as keyof typeof counts]++;
    }
  }

  const summaryParts: string[] = [];
  if (counts.critical > 0) summaryParts.push(`${counts.critical} critical`);
  if (counts.high > 0) summaryParts.push(`${counts.high} high`);
  if (counts.medium > 0) summaryParts.push(`${counts.medium} medium`);
  if (counts.low > 0) summaryParts.push(`${counts.low} low`);

  const actionLabel =
    pendingAction === 'export' ? 'Fix & Export' : 'Fix & Apply';

  return (
    <div className="vb-absolute vb-inset-0 vb-z-50 vb-flex vb-flex-col vb-bg-white vb-border-l vb-border-slate-200">
      {/* Header */}
      <div className="vb-flex vb-items-center vb-justify-between vb-px-4 vb-py-3 vb-border-b vb-border-slate-200">
        <div className="vb-flex vb-items-center vb-gap-2">
          <ShieldIcon />
          <h2 className="vb-text-sm vb-font-semibold vb-text-slate-900">
            Security Scan
          </h2>
        </div>
        <button
          type="button"
          onClick={dismissScanResult}
          className="vb-p-1 vb-text-slate-400 hover:vb-text-slate-600 vb-transition-colors"
          aria-label="Close"
        >
          <XIcon />
        </button>
      </div>

      {/* Summary bar */}
      <div className="vb-px-4 vb-py-2.5 vb-bg-red-50 vb-border-b vb-border-red-100">
        <p className="vb-text-xs vb-font-medium vb-text-red-800">
          {findings.length} issue{findings.length !== 1 ? 's' : ''} found:{' '}
          {summaryParts.join(', ')}
        </p>
        <p className="vb-text-xs vb-text-red-600 vb-mt-0.5">
          {scanResult.summary}
        </p>
      </div>

      {/* Findings list */}
      <div className="vb-flex-1 vb-overflow-y-auto vb-px-4 vb-py-3 vb-space-y-2.5">
        {findings.map((finding, i) => (
          <FindingCard key={i} finding={finding} />
        ))}
      </div>

      {/* Footer actions */}
      <div className="vb-flex vb-items-center vb-justify-between vb-gap-2 vb-px-4 vb-py-3 vb-border-t vb-border-slate-200 vb-bg-slate-50">
        <button
          type="button"
          onClick={proceedWithoutFix}
          disabled={isFixing}
          className="vb-px-3 vb-py-1.5 vb-text-sm vb-font-medium vb-text-slate-600 vb-border vb-border-slate-300 vb-rounded-lg vb-bg-white hover:vb-bg-slate-50 vb-transition-colors disabled:vb-opacity-50"
        >
          Proceed Anyway
        </button>
        <button
          type="button"
          onClick={fixSecurityIssues}
          disabled={isFixing}
          className="vb-flex vb-items-center vb-gap-1.5 vb-px-4 vb-py-1.5 vb-text-sm vb-font-semibold vb-text-white vb-bg-green-600 vb-rounded-lg hover:vb-bg-green-700 vb-transition-colors disabled:vb-opacity-60"
        >
          {isFixing ? (
            <>
              <Spinner />
              Fixing...
            </>
          ) : (
            actionLabel
          )}
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// FindingCard
// ---------------------------------------------------------------------------

function FindingCard({ finding }: { finding: SecurityFinding }) {
  const config =
    SEVERITY_CONFIG[finding.severity] ?? SEVERITY_CONFIG.medium;

  return (
    <div className="vb-rounded-lg vb-border vb-border-slate-200 vb-bg-white vb-overflow-hidden">
      {/* Top: severity + category + file */}
      <div className="vb-flex vb-items-center vb-gap-2 vb-px-3 vb-py-2 vb-bg-slate-50 vb-border-b vb-border-slate-100">
        <span
          className={`vb-inline-flex vb-items-center vb-px-1.5 vb-py-0.5 vb-text-[10px] vb-font-bold vb-uppercase vb-tracking-wide vb-rounded vb-border ${config.badgeCls}`}
        >
          {config.label}
        </span>
        <span className="vb-text-xs vb-font-medium vb-text-slate-700">
          {finding.category}
        </span>
        <span className="vb-ml-auto vb-text-xs vb-text-slate-400 vb-font-mono">
          {finding.file}
          {finding.line ? `:${finding.line}` : ''}
        </span>
      </div>

      {/* Description */}
      <div className="vb-px-3 vb-py-2">
        <p className="vb-text-xs vb-text-slate-600 vb-leading-relaxed">
          {finding.description}
        </p>
        {finding.code_snippet && (
          <pre className="vb-mt-1.5 vb-px-2 vb-py-1.5 vb-text-[11px] vb-font-mono vb-bg-slate-50 vb-rounded vb-border vb-border-slate-100 vb-text-slate-700 vb-overflow-x-auto vb-whitespace-pre-wrap">
            {finding.code_snippet}
          </pre>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

function ShieldIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="vb-text-red-500"
    >
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
      <line x1="12" y1="8" x2="12" y2="12" />
      <line x1="12" y1="16" x2="12.01" y2="16" />
    </svg>
  );
}

function XIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  );
}

function Spinner() {
  return (
    <svg
      className="vb-animate-spin vb-h-3.5 vb-w-3.5"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle
        className="vb-opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        strokeWidth="4"
      />
      <path
        className="vb-opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  );
}
