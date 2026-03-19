/**
 * NewThemeModal — Create a new theme or edit an existing installed theme.
 *
 * Two tabs:
 *  - "Create New" : enter a name for a brand-new theme
 *  - "Edit Existing" : pick an installed WP theme to modify via AI
 */

import {
  useState,
  useRef,
  useEffect,
  useCallback,
  type KeyboardEvent,
} from 'react';
import { getInstalledThemes, type WPTheme } from '@/editor/api/wordpress';

type Tab = 'create' | 'edit';

interface NewThemeModalProps {
  isOpen: boolean;
  onClose: () => void;
  /** Called with (name, themeSlug?). themeSlug is set when editing an existing theme. */
  onCreate: (name: string, themeSlug?: string) => void;
}

export function NewThemeModal({ isOpen, onClose, onCreate }: NewThemeModalProps) {
  const [tab, setTab] = useState<Tab>('create');
  const [name, setName] = useState('');
  const [themes, setThemes] = useState<WPTheme[]>([]);
  const [loadingThemes, setLoadingThemes] = useState(false);
  const [selectedSlug, setSelectedSlug] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Reset state when modal opens.
  useEffect(() => {
    if (isOpen) {
      setName('');
      setSelectedSlug(null);
      setTab('create');
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [isOpen]);

  // Load installed themes when the "Edit Existing" tab is selected.
  useEffect(() => {
    if (isOpen && tab === 'edit' && themes.length === 0 && !loadingThemes) {
      setLoadingThemes(true);
      getInstalledThemes()
        .then(setThemes)
        .catch(() => {})
        .finally(() => setLoadingThemes(false));
    }
  }, [isOpen, tab, themes.length, loadingThemes]);

  const handleCreateSubmit = useCallback(() => {
    const trimmed = name.trim();
    if (!trimmed) return;
    onCreate(trimmed);
  }, [name, onCreate]);

  const handleEditSubmit = useCallback(() => {
    if (!selectedSlug) return;
    const theme = themes.find((t) => t.slug === selectedSlug);
    if (!theme) return;
    onCreate(theme.name, theme.slug);
  }, [selectedSlug, themes, onCreate]);

  const handleKeyDown = useCallback(
    (e: KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleCreateSubmit();
      }
      if (e.key === 'Escape') {
        onClose();
      }
    },
    [handleCreateSubmit, onClose],
  );

  if (!isOpen) return null;

  const tabClass = (t: Tab) =>
    [
      'vb-flex-1 vb-py-2 vb-text-sm vb-font-medium vb-text-center vb-border-b-2 vb-transition-colors vb-cursor-pointer',
      t === tab
        ? 'vb-border-indigo-600 vb-text-indigo-600'
        : 'vb-border-transparent vb-text-slate-400 hover:vb-text-slate-600',
    ].join(' ');

  return (
    <div
      className="vb-fixed vb-inset-0 vb-z-[99999] vb-flex vb-items-center vb-justify-center vb-bg-black/40"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="vb-bg-white vb-rounded-2xl vb-shadow-2xl vb-w-full vb-max-w-lg vb-mx-4 vb-overflow-hidden">
        {/* Header */}
        <div className="vb-flex vb-items-center vb-justify-between vb-px-6 vb-pt-5 vb-pb-2">
          <h2 className="vb-text-lg vb-font-semibold vb-text-slate-800">
            New Theme
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="vb-text-slate-400 hover:vb-text-slate-600 vb-transition-colors"
            aria-label="Close"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="20"
              height="20"
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
          </button>
        </div>

        {/* Tabs */}
        <div className="vb-flex vb-px-6 vb-gap-2">
          <button type="button" className={tabClass('create')} onClick={() => setTab('create')}>
            Create New
          </button>
          <button type="button" className={tabClass('edit')} onClick={() => setTab('edit')}>
            Edit Existing
          </button>
        </div>

        {/* Body */}
        <div className="vb-px-6 vb-py-4" style={{ minHeight: '200px' }}>
          {tab === 'create' && (
            <>
              <label
                htmlFor="vb-theme-name"
                className="vb-block vb-text-sm vb-font-medium vb-text-slate-600 vb-mb-1.5"
              >
                Theme Name
              </label>
              <input
                ref={inputRef}
                id="vb-theme-name"
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="e.g. My Business Theme"
                maxLength={100}
                className="vb-w-full vb-rounded-xl vb-border vb-border-slate-300 vb-bg-slate-50 vb-px-4 vb-py-2.5 vb-text-sm vb-text-slate-800 vb-outline-none vb-transition-colors focus:vb-border-indigo-400 focus:vb-bg-white focus:vb-ring-2 focus:vb-ring-indigo-100"
              />
              <p className="vb-mt-1.5 vb-text-xs vb-text-slate-400">
                This name will identify your theme in WordPress.
              </p>
            </>
          )}

          {tab === 'edit' && (
            <>
              {loadingThemes ? (
                <div className="vb-flex vb-items-center vb-justify-center vb-py-8">
                  <div className="vb-w-6 vb-h-6 vb-border-2 vb-border-indigo-200 vb-border-t-indigo-600 vb-rounded-full vb-animate-spin" />
                  <span className="vb-ml-2 vb-text-sm vb-text-slate-500">Loading themes...</span>
                </div>
              ) : themes.length === 0 ? (
                <p className="vb-text-sm vb-text-slate-400 vb-text-center vb-py-8">
                  No installed themes found.
                </p>
              ) : (
                <div
                  className="vb-space-y-1.5 vb-overflow-y-auto vb-pr-1"
                  style={{ maxHeight: '320px' }}
                >
                  {themes.map((theme) => {
                    const isSelected = selectedSlug === theme.slug;
                    return (
                      <button
                        key={theme.slug}
                        type="button"
                        onClick={() => setSelectedSlug(theme.slug)}
                        className={[
                          'vb-w-full vb-flex vb-items-center vb-gap-3 vb-px-3 vb-py-2.5 vb-rounded-xl vb-border vb-transition-all vb-text-left',
                          isSelected
                            ? 'vb-border-indigo-400 vb-bg-indigo-50 vb-ring-2 vb-ring-indigo-100'
                            : 'vb-border-slate-200 vb-bg-white hover:vb-border-slate-300 hover:vb-bg-slate-50',
                        ].join(' ')}
                      >
                        {/* Thumbnail */}
                        <div className="vb-w-14 vb-h-10 vb-rounded-lg vb-overflow-hidden vb-bg-slate-100 vb-flex-shrink-0 vb-border vb-border-slate-200">
                          {theme.screenshot ? (
                            <img
                              src={theme.screenshot}
                              alt=""
                              className="vb-w-full vb-h-full vb-object-cover"
                            />
                          ) : (
                            <div className="vb-w-full vb-h-full vb-flex vb-items-center vb-justify-center vb-text-slate-300">
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
                                <path d="M12 2L2 7l10 5 10-5-10-5z" />
                                <path d="M2 17l10 5 10-5" />
                                <path d="M2 12l10 5 10-5" />
                              </svg>
                            </div>
                          )}
                        </div>

                        {/* Info */}
                        <div className="vb-flex-1 vb-min-w-0">
                          <div className="vb-flex vb-items-center vb-gap-2">
                            <span className="vb-text-sm vb-font-medium vb-text-slate-800 vb-truncate">
                              {theme.name}
                            </span>
                            {theme.isActive && (
                              <span className="vb-text-[10px] vb-font-semibold vb-uppercase vb-px-1.5 vb-py-0.5 vb-rounded vb-bg-green-100 vb-text-green-700 vb-flex-shrink-0">
                                Active
                              </span>
                            )}
                          </div>
                          <span className="vb-text-xs vb-text-slate-400">
                            v{theme.version} &middot; {theme.author.replace(/<[^>]*>/g, '')}
                          </span>
                        </div>

                        {/* Selection indicator */}
                        {isSelected && (
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.5"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            className="vb-text-indigo-600 vb-flex-shrink-0"
                          >
                            <polyline points="20 6 9 17 4 12" />
                          </svg>
                        )}
                      </button>
                    );
                  })}
                </div>
              )}
            </>
          )}
        </div>

        {/* Footer */}
        <div className="vb-flex vb-items-center vb-justify-end vb-gap-3 vb-px-6 vb-pb-5">
          <button
            type="button"
            onClick={onClose}
            className="vb-px-4 vb-py-2 vb-rounded-lg vb-text-sm vb-font-medium vb-text-slate-600 vb-transition-colors hover:vb-bg-slate-100"
          >
            Cancel
          </button>
          {tab === 'create' ? (
            <button
              type="button"
              onClick={handleCreateSubmit}
              disabled={!name.trim()}
              className="vb-px-4 vb-py-2 vb-rounded-lg vb-text-sm vb-font-medium vb-text-white vb-bg-indigo-600 vb-transition-colors hover:vb-bg-indigo-500 active:vb-bg-indigo-700 disabled:vb-bg-slate-200 disabled:vb-text-slate-400 disabled:vb-cursor-not-allowed"
            >
              Create Theme
            </button>
          ) : (
            <button
              type="button"
              onClick={handleEditSubmit}
              disabled={!selectedSlug}
              className="vb-px-4 vb-py-2 vb-rounded-lg vb-text-sm vb-font-medium vb-text-white vb-bg-indigo-600 vb-transition-colors hover:vb-bg-indigo-500 active:vb-bg-indigo-700 disabled:vb-bg-slate-200 disabled:vb-text-slate-400 disabled:vb-cursor-not-allowed"
            >
              Edit Theme
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
