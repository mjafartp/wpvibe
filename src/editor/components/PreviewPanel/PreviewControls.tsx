/**
 * PreviewControls — Bottom toolbar for the preview panel.
 *
 * Displays undo/redo navigation, the current version indicator, and
 * primary action buttons (Apply Theme, Export ZIP). The entire bar is
 * hidden when no theme versions exist yet.
 */

interface PreviewControlsProps {
  canUndo: boolean;
  canRedo: boolean;
  currentVersion: number;
  totalVersions: number;
  hasVersions: boolean;
  isApplying: boolean;
  isExporting: boolean;
  onUndo: () => void;
  onRedo: () => void;
  onApply: () => void;
  onExport: () => void;
}

export function PreviewControls({
  canUndo,
  canRedo,
  currentVersion,
  totalVersions,
  hasVersions,
  isApplying,
  isExporting,
  onUndo,
  onRedo,
  onApply,
  onExport,
}: PreviewControlsProps) {
  if (!hasVersions) return null;

  return (
    <div className="vb-flex vb-items-center vb-justify-between vb-border-t vb-border-slate-200 vb-bg-white vb-px-4 vb-py-2.5">
      {/* Left side: undo / redo + version indicator */}
      <div className="vb-flex vb-items-center vb-gap-2">
        {/* Undo button */}
        <button
          type="button"
          onClick={onUndo}
          disabled={!canUndo}
          className="vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-md vb-border vb-border-slate-200 vb-bg-white vb-text-slate-600 vb-transition-colors hover:vb-bg-slate-50 hover:vb-text-slate-800 disabled:vb-text-slate-300 disabled:vb-bg-slate-50 disabled:vb-cursor-not-allowed"
          aria-label="Undo"
          title="Undo"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="15 18 9 12 15 6" />
          </svg>
        </button>

        {/* Redo button */}
        <button
          type="button"
          onClick={onRedo}
          disabled={!canRedo}
          className="vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-md vb-border vb-border-slate-200 vb-bg-white vb-text-slate-600 vb-transition-colors hover:vb-bg-slate-50 hover:vb-text-slate-800 disabled:vb-text-slate-300 disabled:vb-bg-slate-50 disabled:vb-cursor-not-allowed"
          aria-label="Redo"
          title="Redo"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="9 18 15 12 9 6" />
          </svg>
        </button>

        {/* Version indicator */}
        <span className="vb-text-xs vb-text-slate-400 vb-ml-1 vb-select-none">
          Version {currentVersion} of {totalVersions}
        </span>
      </div>

      {/* Right side: action buttons */}
      <div className="vb-flex vb-items-center vb-gap-2">
        {/* Export ZIP button (outlined) */}
        <button
          type="button"
          onClick={onExport}
          disabled={isExporting}
          className="vb-flex vb-items-center vb-gap-1.5 vb-px-3.5 vb-py-1.5 vb-text-sm vb-font-medium vb-rounded-lg vb-border vb-border-slate-300 vb-bg-white vb-text-slate-700 vb-transition-colors hover:vb-bg-slate-50 hover:vb-border-slate-400 disabled:vb-opacity-60 disabled:vb-cursor-not-allowed"
        >
          {isExporting ? (
            <Spinner />
          ) : null}
          Export ZIP
        </button>

        {/* Apply Theme button (primary) */}
        <button
          type="button"
          onClick={onApply}
          disabled={isApplying}
          className="vb-flex vb-items-center vb-gap-1.5 vb-px-3.5 vb-py-1.5 vb-text-sm vb-font-medium vb-rounded-lg vb-bg-indigo-600 vb-text-white vb-transition-colors hover:vb-bg-indigo-700 active:vb-bg-indigo-800 disabled:vb-opacity-60 disabled:vb-cursor-not-allowed"
        >
          {isApplying ? (
            <Spinner />
          ) : null}
          Apply Theme
        </button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Internal spinner component
// ---------------------------------------------------------------------------

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
