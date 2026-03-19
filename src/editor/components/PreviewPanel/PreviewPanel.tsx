/**
 * PreviewPanel — Live theme preview with viewport controls and version history.
 *
 * Renders the AI-generated theme preview in a sandboxed iFrame.
 * Includes a top bar with viewport toggle + version indicator,
 * the iFrame itself, and a bottom toolbar with undo/redo + actions.
 *
 * When no preview is available, shows a placeholder prompting the user
 * to start chatting.
 */

import { useRef, useEffect, useCallback, useState, useMemo } from 'react';
import { useThemePreview } from '@/editor/hooks/useThemePreview';
import { useEditorStore } from '@/editor/store/editorStore';
import { ViewportToggle } from './ViewportToggle';
import { CodeEditor } from './CodeEditor';
import { PreviewControls } from './PreviewControls';
import { SecurityPanel } from '../SecurityPanel/SecurityPanel';
import type { SelectedSection } from '@/editor/types';

/**
 * Validate that a preview URL is safe to load in the iframe.
 * Only allows same-origin URLs (WP REST API preview endpoint).
 */
function isSafePreviewUrl(url: string): boolean {
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.origin === window.location.origin;
  } catch {
    return false;
  }
}

/** Pixel widths for each viewport mode. */
const VIEWPORT_WIDTHS: Record<string, string> = {
  mobile: '375px',
  tablet: '768px',
  desktop: '100%',
};

/**
 * JS to inject into the iframe to enable element selection.
 * Highlights on hover, captures click, posts message back to parent.
 */
const SELECT_MODE_SCRIPT = `
(function() {
  if (window.__vbSelectMode) return;
  window.__vbSelectMode = true;

  var overlay = null;
  var lastTarget = null;

  function getLabel(el) {
    if (el.id) return '#' + el.id;
    var aria = el.getAttribute('aria-label');
    if (aria) return aria;
    var heading = el.querySelector('h1, h2, h3');
    if (heading && heading.textContent.trim()) {
      return heading.textContent.trim().substring(0, 40);
    }
    var cls = Array.from(el.classList).slice(0, 2).join('.');
    return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
  }

  function getSelector(el) {
    var parts = [];
    var current = el;
    while (current && current !== document.body && parts.length < 4) {
      var tag = current.tagName.toLowerCase();
      if (current.id) {
        parts.unshift(tag + '#' + current.id);
        break;
      }
      var cls = Array.from(current.classList).slice(0, 2).join('.');
      parts.unshift(tag + (cls ? '.' + cls : ''));
      current = current.parentElement;
    }
    return parts.join(' > ');
  }

  function findSection(el) {
    var sectionTags = ['SECTION','HEADER','FOOTER','NAV','MAIN','ARTICLE','ASIDE','DIV'];
    var current = el;
    while (current && current !== document.body) {
      if (sectionTags.indexOf(current.tagName) !== -1) {
        if (current.tagName !== 'DIV' || current.parentElement === document.body || current.classList.length > 0) {
          return current;
        }
      }
      current = current.parentElement;
    }
    return el;
  }

  function showOverlay(el) {
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.style.cssText = 'position:absolute;pointer-events:none;border:2px solid #6366f1;background:rgba(99,102,241,0.08);border-radius:4px;z-index:999999;transition:all 0.15s ease;';
      document.body.appendChild(overlay);
    }
    var r = el.getBoundingClientRect();
    overlay.style.top = (r.top + window.scrollY) + 'px';
    overlay.style.left = (r.left + window.scrollX) + 'px';
    overlay.style.width = r.width + 'px';
    overlay.style.height = r.height + 'px';
    overlay.style.display = 'block';
  }

  function hideOverlay() {
    if (overlay) overlay.style.display = 'none';
  }

  function onMove(e) {
    var section = findSection(e.target);
    if (section !== lastTarget) {
      lastTarget = section;
      showOverlay(section);
    }
  }

  function onLeave() {
    hideOverlay();
    lastTarget = null;
  }

  function onClick(e) {
    e.preventDefault();
    e.stopPropagation();
    var section = findSection(e.target);
    var snippet = section.outerHTML;
    if (snippet.length > 500) {
      var inner = section.innerHTML;
      snippet = section.outerHTML.substring(0, section.outerHTML.indexOf(inner)) + '</' + section.tagName.toLowerCase() + '>';
    }
    window.parent.postMessage({
      type: 'vb-section-selected',
      data: {
        selector: getSelector(section),
        tagName: section.tagName,
        label: getLabel(section),
        outerHtmlSnippet: snippet.substring(0, 500)
      }
    }, '*');
  }

  document.addEventListener('mousemove', onMove, true);
  document.addEventListener('mouseleave', onLeave, true);
  document.addEventListener('click', onClick, true);

  // Store references so deselect can remove them.
  window.__vbSelectHandlers = { onMove: onMove, onLeave: onLeave, onClick: onClick };
})();
`;

const DESELECT_MODE_SCRIPT = `
(function() {
  window.__vbSelectMode = false;
  // Remove event listeners.
  var h = window.__vbSelectHandlers;
  if (h) {
    document.removeEventListener('mousemove', h.onMove, true);
    document.removeEventListener('mouseleave', h.onLeave, true);
    document.removeEventListener('click', h.onClick, true);
    window.__vbSelectHandlers = null;
  }
  // Remove overlay.
  var overlays = document.querySelectorAll('div[style*="z-index:999999"]');
  overlays.forEach(function(el) { el.remove(); });
})();
`;

export function PreviewPanel() {
  const {
    previewUrl,
    viewportSize,
    themeVersions,
    currentVersionIndex,
    canUndo,
    canRedo,
    hasVersions,
    isApplying,
    isExporting,
    setViewportSize,
    undo,
    redo,
    applyTheme,
    exportTheme,
  } = useThemePreview();

  const selectModeActive = useEditorStore((s) => s.selectModeActive);
  const selectedSection = useEditorStore((s) => s.selectedSection);
  const setSelectModeActive = useEditorStore((s) => s.setSelectModeActive);
  const setSelectedSection = useEditorStore((s) => s.setSelectedSection);
  const isScanning = useEditorStore((s) => s.isScanning);
  const scanResult = useEditorStore((s) => s.scanResult);

  const iframeRef = useRef<HTMLIFrameElement>(null);
  const [viewMode, setViewMode] = useState<'preview' | 'code'>('preview');

  // Only allow same-origin preview URLs to prevent loading untrusted content.
  const safePreviewUrl = useMemo(
    () => (previewUrl && isSafePreviewUrl(previewUrl) ? previewUrl : ''),
    [previewUrl],
  );

  const currentVersion = currentVersionIndex + 1;
  const totalVersions = themeVersions.length;

  // Listen for selection messages from the iframe (validate origin)
  useEffect(() => {
    function handleMessage(e: MessageEvent) {
      if (e.origin !== window.location.origin) return;
      if (e.data?.type === 'vb-section-selected') {
        const data = e.data.data as SelectedSection;
        setSelectedSection(data);
      }
    }
    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, [setSelectedSection]);

  // Inject/remove select mode script when toggled
  useEffect(() => {
    const iframe = iframeRef.current;
    if (!iframe) return;

    try {
      const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
      if (!iframeDoc?.body) return;

      if (selectModeActive) {
        const script = iframeDoc.createElement('script');
        script.textContent = SELECT_MODE_SCRIPT;
        iframeDoc.body.appendChild(script);
      } else {
        const script = iframeDoc.createElement('script');
        script.textContent = DESELECT_MODE_SCRIPT;
        iframeDoc.body.appendChild(script);
      }
    } catch {
      // Cross-origin — selection won't work for external preview URLs.
    }
  }, [selectModeActive]);

  // Re-inject select mode script after iframe loads (if mode is active)
  const handleIframeLoad = useCallback(() => {
    if (!selectModeActive) return;
    const iframe = iframeRef.current;
    if (!iframe) return;
    try {
      const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
      if (!iframeDoc?.body) return;
      const script = iframeDoc.createElement('script');
      script.textContent = SELECT_MODE_SCRIPT;
      iframeDoc.body.appendChild(script);
    } catch {
      // Cross-origin
    }
  }, [selectModeActive]);

  const toggleSelectMode = useCallback(() => {
    setSelectModeActive(!selectModeActive);
  }, [selectModeActive, setSelectModeActive]);

  return (
    <div className="vb-relative vb-flex vb-flex-col vb-h-full vb-bg-slate-100 vb-border-l vb-border-slate-200">
      {/* Top bar: view mode toggle + viewport/select controls + version indicator */}
      <div className="vb-flex vb-items-center vb-justify-between vb-border-b vb-border-slate-200 vb-bg-white vb-px-4 vb-py-2">
        <div className="vb-flex vb-items-center vb-gap-3">
          {/* Preview / Code toggle */}
          <div className="vb-inline-flex vb-items-center vb-rounded-lg vb-border vb-border-slate-200 vb-bg-slate-100 vb-p-0.5">
            <button
              type="button"
              onClick={() => setViewMode('preview')}
              className={[
                'vb-flex vb-items-center vb-gap-1.5 vb-px-3 vb-py-1.5 vb-text-xs vb-font-medium vb-rounded-md vb-transition-colors vb-select-none',
                viewMode === 'preview'
                  ? 'vb-bg-indigo-600 vb-text-white vb-shadow-sm'
                  : 'vb-text-slate-500 hover:vb-text-slate-700 hover:vb-bg-slate-200',
              ].join(' ')}
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
              Preview
            </button>
            <button
              type="button"
              onClick={() => setViewMode('code')}
              className={[
                'vb-flex vb-items-center vb-gap-1.5 vb-px-3 vb-py-1.5 vb-text-xs vb-font-medium vb-rounded-md vb-transition-colors vb-select-none',
                viewMode === 'code'
                  ? 'vb-bg-indigo-600 vb-text-white vb-shadow-sm'
                  : 'vb-text-slate-500 hover:vb-text-slate-700 hover:vb-bg-slate-200',
              ].join(' ')}
            >
              <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <polyline points="16 18 22 12 16 6" />
                <polyline points="8 6 2 12 8 18" />
              </svg>
              Code
            </button>
          </div>

          {/* Viewport + Select — only in preview mode */}
          {viewMode === 'preview' && (
            <>
              <ViewportToggle
                currentSize={viewportSize}
                onChange={setViewportSize}
              />

              {safePreviewUrl && (
                <button
                  type="button"
                  onClick={toggleSelectMode}
                  className={[
                    'vb-flex vb-items-center vb-gap-1.5 vb-px-2.5 vb-py-1.5 vb-rounded-lg vb-text-xs vb-font-medium vb-border vb-transition-colors',
                    selectModeActive
                      ? 'vb-bg-indigo-50 vb-border-indigo-300 vb-text-indigo-700'
                      : 'vb-bg-white vb-border-slate-200 vb-text-slate-600 hover:vb-bg-slate-50 hover:vb-border-slate-300',
                  ].join(' ')}
                  title={selectModeActive ? 'Exit select mode' : 'Click a section in the preview to edit it'}
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z" />
                    <path d="M13 13l6 6" />
                  </svg>
                  Select
                </button>
              )}
            </>
          )}
        </div>

        {hasVersions && (
          <span className="vb-text-xs vb-text-slate-400 vb-select-none">
            Version {currentVersion} of {totalVersions}
          </span>
        )}
      </div>

      {/* === CODE VIEW === */}
      {viewMode === 'code' && (
        <div className="vb-flex-1 vb-overflow-hidden">
          <CodeEditor />
        </div>
      )}

      {/* === PREVIEW VIEW === */}
      {/* Selected section indicator */}
      {viewMode === 'preview' && selectedSection && (
        <div className="vb-flex vb-items-center vb-justify-between vb-px-4 vb-py-2 vb-bg-indigo-50 vb-border-b vb-border-indigo-200 vb-flex-shrink-0">
          <div className="vb-flex vb-items-center vb-gap-2">
            <span className="vb-text-xs vb-font-medium vb-text-indigo-700">Selected:</span>
            <span className="vb-text-xs vb-text-indigo-600 vb-font-mono vb-bg-indigo-100 vb-px-2 vb-py-0.5 vb-rounded">
              {selectedSection.label}
            </span>
          </div>
          <button
            type="button"
            onClick={() => setSelectedSection(null)}
            className="vb-text-indigo-400 hover:vb-text-indigo-600 vb-transition-colors"
            aria-label="Clear selection"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
      )}

      {/* iFrame preview area */}
      {viewMode === 'preview' && <div className="vb-flex-1 vb-flex vb-items-center vb-justify-center vb-overflow-hidden vb-p-4">
        {safePreviewUrl ? (
          <div
            className="vb-preview-iframe-container vb-h-full vb-bg-white vb-rounded-lg vb-shadow-md vb-overflow-hidden vb-transition-[width] vb-duration-300 vb-ease-in-out"
            style={{ width: VIEWPORT_WIDTHS[viewportSize] }}
          >
            <iframe
              ref={iframeRef}
              src={safePreviewUrl}
              title="Theme Preview"
              sandbox="allow-same-origin allow-scripts"
              referrerPolicy="no-referrer"
              className="vb-w-full vb-h-full vb-border-0"
              onLoad={handleIframeLoad}
              style={selectModeActive ? { cursor: 'crosshair' } : undefined}
            />
          </div>
        ) : (
          /* Placeholder when no preview is available */
          <div className="vb-text-center vb-px-8">
            <div className="vb-mb-4 vb-text-slate-300 vb-flex vb-justify-center">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="56"
                height="56"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
                <line x1="8" y1="21" x2="16" y2="21" />
                <line x1="12" y1="17" x2="12" y2="21" />
              </svg>
            </div>
            <h3 className="vb-text-base vb-font-semibold vb-text-slate-500 vb-mb-2">
              Theme Preview
            </h3>
            <p className="vb-text-sm vb-text-slate-400 vb-max-w-xs vb-leading-relaxed">
              Preview will appear here when you generate a theme.
              Start by describing your ideal design in the chat.
            </p>
          </div>
        )}
      </div>}

      {/* Scanning overlay */}
      {isScanning && (
        <div className="vb-absolute vb-inset-0 vb-z-40 vb-flex vb-flex-col vb-items-center vb-justify-center vb-bg-white/80 vb-backdrop-blur-sm">
          <svg
            className="vb-animate-spin vb-h-8 vb-w-8 vb-text-indigo-600 vb-mb-3"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle className="vb-opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="vb-opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <p className="vb-text-sm vb-font-medium vb-text-slate-700">Scanning for security issues...</p>
          <p className="vb-text-xs vb-text-slate-400 vb-mt-1">This may take a moment</p>
        </div>
      )}

      {/* Security findings panel */}
      {scanResult && !scanResult.safe && <SecurityPanel />}

      {/* Bottom toolbar: undo/redo + Apply / Export — only in preview mode */}
      {viewMode === 'preview' && <PreviewControls
        canUndo={canUndo}
        canRedo={canRedo}
        currentVersion={currentVersion}
        totalVersions={totalVersions}
        hasVersions={hasVersions}
        isApplying={isApplying}
        isExporting={isExporting}
        onUndo={undo}
        onRedo={redo}
        onApply={applyTheme}
        onExport={exportTheme}
      />}
    </div>
  );
}
