/**
 * FigmaButton — Opens the Figma frame selector modal.
 *
 * Enabled only when a Figma Personal Access Token is configured
 * (indicated by `window.wpvibeData.hasFigma`).
 */

interface FigmaButtonProps {
  onClick?: () => void;
  disabled?: boolean;
}

export function FigmaButton({ onClick, disabled }: FigmaButtonProps) {
  const hasFigma = window.wpvibeData?.hasFigma ?? false;
  const isDisabled = disabled || !hasFigma;

  return (
    <button
      type="button"
      disabled={isDisabled}
      onClick={onClick}
      title={hasFigma ? 'Attach Figma frame' : 'Figma not configured — set up in Settings'}
      className={[
        'vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-lg vb-border vb-border-slate-200 vb-bg-white vb-transition-colors',
        isDisabled
          ? 'vb-text-slate-300 vb-cursor-not-allowed'
          : 'vb-text-slate-500 hover:vb-bg-slate-50 hover:vb-text-indigo-600 hover:vb-border-indigo-200',
      ].join(' ')}
      aria-label="Attach Figma frame"
    >
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
        <circle cx="12" cy="12" r="10" />
        <line x1="12" y1="8" x2="12" y2="16" />
        <line x1="8" y1="12" x2="16" y2="12" />
      </svg>
    </button>
  );
}
