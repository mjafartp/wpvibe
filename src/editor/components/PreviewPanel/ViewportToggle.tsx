/**
 * ViewportToggle — A row of toggle buttons for switching the preview
 * iFrame viewport size between mobile, tablet, and desktop.
 *
 * The active button is highlighted with an indigo background and white text.
 * Inactive buttons show gray text with a subtle hover highlight.
 * The button group has a pill shape with rounded outer edges.
 */

import type { ViewportSize } from '@/editor/store/editorStore';

interface ViewportToggleProps {
  currentSize: ViewportSize;
  onChange: (size: ViewportSize) => void;
}

interface ViewportOption {
  size: ViewportSize;
  label: string;
  icon: React.ReactNode;
}

const viewportOptions: ViewportOption[] = [
  {
    size: 'mobile',
    label: 'Mobile (375px)',
    icon: (
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
        <rect x="5" y="2" width="14" height="20" rx="2" ry="2" />
        <line x1="12" y1="18" x2="12.01" y2="18" />
      </svg>
    ),
  },
  {
    size: 'tablet',
    label: 'Tablet (768px)',
    icon: (
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
        <rect x="4" y="2" width="16" height="20" rx="2" ry="2" />
        <line x1="12" y1="18" x2="12.01" y2="18" />
      </svg>
    ),
  },
  {
    size: 'desktop',
    label: 'Desktop (100%)',
    icon: (
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
        <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
        <line x1="8" y1="21" x2="16" y2="21" />
        <line x1="12" y1="17" x2="12" y2="21" />
      </svg>
    ),
  },
];

export function ViewportToggle({ currentSize, onChange }: ViewportToggleProps) {
  return (
    <div
      className="vb-inline-flex vb-items-center vb-rounded-lg vb-border vb-border-slate-200 vb-bg-slate-100 vb-p-0.5"
      role="radiogroup"
      aria-label="Preview viewport size"
    >
      {viewportOptions.map((option) => {
        const isActive = currentSize === option.size;

        return (
          <button
            key={option.size}
            type="button"
            role="radio"
            aria-checked={isActive}
            aria-label={option.label}
            title={option.label}
            onClick={() => onChange(option.size)}
            className={[
              'vb-flex vb-items-center vb-gap-1.5 vb-px-3 vb-py-1.5 vb-text-xs vb-font-medium vb-rounded-md vb-transition-colors vb-select-none',
              isActive
                ? 'vb-bg-indigo-600 vb-text-white vb-shadow-sm'
                : 'vb-text-slate-500 hover:vb-text-slate-700 hover:vb-bg-slate-200',
            ].join(' ')}
          >
            {option.icon}
            <span className="vb-hidden sm:vb-inline">
              {option.size.charAt(0).toUpperCase() + option.size.slice(1)}
            </span>
          </button>
        );
      })}
    </div>
  );
}
