/**
 * CodeEditor — View and edit theme source files with a code-editor look.
 *
 * Dark background, line numbers, Prism.js syntax highlighting overlay.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { useEditorStore } from '@/editor/store/editorStore';
import { getThemeFiles, saveThemeFiles, type ThemeFileData } from '@/editor/api/wordpress';
import Prism from 'prismjs';
import 'prismjs/components/prism-markup';
import 'prismjs/components/prism-css';
import 'prismjs/components/prism-javascript';
import 'prismjs/components/prism-json';
import 'prismjs/components/prism-php';
import 'prismjs/components/prism-markup-templating'; // required before php

function getPrismLanguage(path: string): string {
  const ext = path.split('.').pop()?.toLowerCase() ?? '';
  switch (ext) {
    case 'php': return 'php';
    case 'css': return 'css';
    case 'js': return 'javascript';
    case 'json': return 'json';
    case 'html': return 'markup';
    case 'svg': return 'markup';
    default: return 'plain';
  }
}

function highlight(code: string, path: string): string {
  const lang = getPrismLanguage(path);
  if (lang === 'plain' || !Prism.languages[lang]) return escapeHtml(code);
  return Prism.highlight(code, Prism.languages[lang], lang);
}

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function getFileIcon(path: string): string {
  const ext = path.split('.').pop()?.toLowerCase() ?? '';
  switch (ext) {
    case 'php': return 'PHP';
    case 'css': return 'CSS';
    case 'js': return 'JS';
    case 'json': return 'JSON';
    case 'html': return 'HTML';
    case 'svg': return 'SVG';
    default: return ext.toUpperCase();
  }
}

function getExtColor(path: string): string {
  const ext = path.split('.').pop()?.toLowerCase() ?? '';
  switch (ext) {
    case 'php': return '#c084fc';
    case 'css': return '#60a5fa';
    case 'js': return '#fbbf24';
    case 'json': return '#34d399';
    case 'html': return '#f87171';
    default: return '#94a3b8';
  }
}

function getExtBg(path: string): string {
  const ext = path.split('.').pop()?.toLowerCase() ?? '';
  switch (ext) {
    case 'php': return 'rgba(192,132,252,0.15)';
    case 'css': return 'rgba(96,165,250,0.15)';
    case 'js': return 'rgba(251,191,36,0.15)';
    case 'json': return 'rgba(52,211,153,0.15)';
    case 'html': return 'rgba(248,113,113,0.15)';
    default: return 'rgba(148,163,184,0.15)';
  }
}

export function CodeEditor() {
  const currentSessionId = useEditorStore((s) => s.currentSessionId);
  const [files, setFiles] = useState<ThemeFileData[]>([]);
  const [activeFileIndex, setActiveFileIndex] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);
  const [saveMessage, setSaveMessage] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const lineNumbersRef = useRef<HTMLDivElement>(null);
  const highlightRef = useRef<HTMLPreElement>(null);

  // Update syntax highlight layer whenever the active file content changes.
  // We use a ref + useEffect to avoid dangerouslySetInnerHTML.
  // Prism.highlight() escapes all HTML entities before tokenising, so the
  // output is safe to set as innerHTML inside this controlled admin context.
  useEffect(() => {
    if (!highlightRef.current || !activeFile) return;
    highlightRef.current.innerHTML = highlight(activeFile.content, activeFile.path) + '\n';
  });

  useEffect(() => {
    if (!currentSessionId) return;
    setIsLoading(true);
    getThemeFiles(currentSessionId)
      .then((f) => {
        setFiles(f);
        setActiveFileIndex(0);
        setHasChanges(false);
      })
      .catch(() => setFiles([]))
      .finally(() => setIsLoading(false));
  }, [currentSessionId]);

  const handleFileSelect = useCallback((index: number) => {
    setActiveFileIndex(index);
  }, []);

  const handleCodeChange = useCallback((e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const newContent = e.target.value;
    setFiles((prev) => {
      const updated = [...prev];
      updated[activeFileIndex] = { ...updated[activeFileIndex], content: newContent };
      return updated;
    });
    setHasChanges(true);
    setSaveMessage('');
  }, [activeFileIndex]);

  const handleSave = useCallback(async () => {
    if (!currentSessionId || !hasChanges || isSaving) return;
    setIsSaving(true);
    setSaveMessage('');
    try {
      const result = await saveThemeFiles(currentSessionId, files);
      setHasChanges(false);
      setSaveMessage(`v${result.versionNumber} saved`);
      const store = useEditorStore.getState();
      store.loadThemeVersions(currentSessionId);
      useEditorStore.setState({ previewUrl: result.previewUrl });
      setTimeout(() => setSaveMessage(''), 3000);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Save failed.';
      useEditorStore.setState({ error: msg });
    } finally {
      setIsSaving(false);
    }
  }, [currentSessionId, files, hasChanges, isSaving]);

  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === 's') {
        e.preventDefault();
        handleSave();
      }
    }
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [handleSave]);

  const handleTextareaKeyDown = useCallback((e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Tab') {
      e.preventDefault();
      const ta = e.currentTarget;
      const start = ta.selectionStart;
      const end = ta.selectionEnd;
      const value = ta.value;
      const newValue = value.substring(0, start) + '  ' + value.substring(end);

      setFiles((prev) => {
        const updated = [...prev];
        updated[activeFileIndex] = { ...updated[activeFileIndex], content: newValue };
        return updated;
      });
      setHasChanges(true);

      requestAnimationFrame(() => {
        if (textareaRef.current) {
          textareaRef.current.selectionStart = start + 2;
          textareaRef.current.selectionEnd = start + 2;
        }
      });
    }
  }, [activeFileIndex]);

  // Sync scroll between textarea, line numbers, and highlight overlay
  const handleScroll = useCallback(() => {
    const ta = textareaRef.current;
    if (!ta) return;
    if (lineNumbersRef.current) lineNumbersRef.current.scrollTop = ta.scrollTop;
    if (highlightRef.current) {
      highlightRef.current.scrollTop = ta.scrollTop;
      highlightRef.current.scrollLeft = ta.scrollLeft;
    }
  }, []);

  const activeFile = files[activeFileIndex] ?? null;
  const lineCount = activeFile ? activeFile.content.split('\n').length : 0;

  if (isLoading) {
    return (
      <div className="vb-flex vb-items-center vb-justify-center vb-h-full" style={{ background: '#0d1117', color: '#6e7681' }}>
        Loading files...
      </div>
    );
  }

  if (files.length === 0) {
    return (
      <div className="vb-flex vb-items-center vb-justify-center vb-h-full" style={{ background: '#0d1117', color: '#6e7681' }}>
        No theme files yet. Generate a theme first.
      </div>
    );
  }

  return (
    <div className="vb-flex vb-flex-col vb-h-full" style={{ background: '#0d1117' }}>
      {/* Top bar */}
      <div
        className="vb-flex vb-items-center vb-justify-between vb-px-3 vb-py-2 vb-flex-shrink-0"
        style={{ background: '#161b22', borderBottom: '1px solid #30363d' }}
      >
        <div className="vb-flex vb-items-center vb-gap-2">
          {/* Open file tabs */}
          {activeFile && (
            <div className="vb-flex vb-items-center vb-gap-1.5" style={{ color: '#e6edf3' }}>
              <span
                style={{
                  fontSize: '10px',
                  fontWeight: 700,
                  padding: '2px 6px',
                  borderRadius: '4px',
                  color: getExtColor(activeFile.path),
                  background: getExtBg(activeFile.path),
                  fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                }}
              >
                {getFileIcon(activeFile.path)}
              </span>
              <span style={{
                fontSize: '13px',
                fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                color: '#e6edf3',
              }}>
                {activeFile.path}
              </span>
            </div>
          )}
          {hasChanges && (
            <span
              style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: '#d29922',
                display: 'inline-block',
              }}
              title="Unsaved changes"
            />
          )}
        </div>
        <div className="vb-flex vb-items-center vb-gap-2">
          {saveMessage && (
            <span style={{ fontSize: '12px', color: '#3fb950', fontWeight: 500 }}>{saveMessage}</span>
          )}
          <button
            type="button"
            onClick={handleSave}
            disabled={!hasChanges || isSaving}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '6px',
              padding: '5px 12px',
              fontSize: '12px',
              fontWeight: 500,
              borderRadius: '6px',
              border: '1px solid #30363d',
              cursor: hasChanges && !isSaving ? 'pointer' : 'not-allowed',
              background: hasChanges && !isSaving ? '#238636' : '#21262d',
              color: hasChanges && !isSaving ? '#ffffff' : '#484f58',
              transition: 'background 0.15s',
            }}
          >
            {isSaving ? (
              <svg className="vb-animate-spin" style={{ width: '14px', height: '14px' }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle style={{ opacity: 0.25 }} cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path style={{ opacity: 0.75 }} fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            ) : (
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                <polyline points="17 21 17 13 7 13 7 21" />
                <polyline points="7 3 7 8 15 8" />
              </svg>
            )}
            {isSaving ? 'Saving...' : 'Save'}
            <kbd style={{ fontSize: '9px', opacity: 0.6, marginLeft: '2px', color: 'inherit' }}>
              {navigator.platform?.includes('Mac') ? '\u2318S' : 'Ctrl+S'}
            </kbd>
          </button>
        </div>
      </div>

      <div className="vb-flex vb-flex-1 vb-overflow-hidden">
        {/* File explorer sidebar */}
        <div
          className="vb-flex-shrink-0 vb-overflow-y-auto"
          style={{
            width: '180px',
            background: '#0d1117',
            borderRight: '1px solid #21262d',
          }}
        >
          <div style={{ padding: '8px 12px 4px', fontSize: '11px', fontWeight: 600, color: '#8b949e', textTransform: 'uppercase', letterSpacing: '0.5px' }}>
            Explorer
          </div>
          {files.map((file, index) => {
            const isActive = index === activeFileIndex;
            return (
              <button
                key={file.path}
                type="button"
                onClick={() => handleFileSelect(index)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  width: '100%',
                  padding: '6px 12px',
                  textAlign: 'left',
                  fontSize: '12px',
                  border: 'none',
                  cursor: 'pointer',
                  transition: 'background 0.1s',
                  background: isActive ? '#1f6feb22' : 'transparent',
                  color: isActive ? '#e6edf3' : '#8b949e',
                  borderLeft: isActive ? '2px solid #1f6feb' : '2px solid transparent',
                  fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                }}
                onMouseEnter={(e) => {
                  if (!isActive) e.currentTarget.style.background = '#161b22';
                }}
                onMouseLeave={(e) => {
                  if (!isActive) e.currentTarget.style.background = 'transparent';
                }}
              >
                <span style={{
                  fontSize: '9px',
                  fontWeight: 700,
                  padding: '1px 4px',
                  borderRadius: '3px',
                  color: getExtColor(file.path),
                  background: getExtBg(file.path),
                  flexShrink: 0,
                }}>
                  {getFileIcon(file.path)}
                </span>
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {file.path}
                </span>
              </button>
            );
          })}
        </div>

        {/* Code area with line numbers */}
        <div className="vb-flex vb-flex-1 vb-overflow-hidden" style={{ background: '#0d1117' }}>
          {activeFile && (
            <>
              {/* Line numbers gutter */}
              <div
                ref={lineNumbersRef}
                aria-hidden="true"
                style={{
                  flexShrink: 0,
                  width: '54px',
                  overflowY: 'hidden',
                  background: '#0d1117',
                  borderRight: '1px solid #21262d',
                  paddingTop: '12px',
                  paddingBottom: '12px',
                  userSelect: 'none',
                  fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                  fontSize: '13px',
                  lineHeight: '20px',
                  color: '#484f58',
                  textAlign: 'right',
                }}
              >
                {Array.from({ length: lineCount }, (_, i) => (
                  <div key={i} style={{ paddingRight: '12px', height: '20px' }}>
                    {i + 1}
                  </div>
                ))}
              </div>

              {/* Overlay wrapper: highlight pre + transparent textarea stacked */}
              <div className="vb-code-editor-area" style={{ flex: 1, position: 'relative', overflow: 'hidden' }}>
                {/* Prism highlight layer — filled by useEffect via ref.innerHTML */}
                <pre
                  ref={highlightRef}
                  aria-hidden="true"
                  style={{
                    position: 'absolute',
                    inset: 0,
                    margin: 0,
                    padding: '12px 16px',
                    background: 'transparent',
                    color: '#e6edf3',
                    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                    fontSize: '13px',
                    lineHeight: '20px',
                    tabSize: 2,
                    whiteSpace: 'pre',
                    overflowX: 'auto',
                    overflowY: 'auto',
                    wordBreak: 'normal',
                    pointerEvents: 'none',
                    boxSizing: 'border-box',
                  }}
                />
                {/* Editable textarea — transparent so highlight shows through */}
                <textarea
                  ref={textareaRef}
                  value={activeFile.content}
                  onChange={handleCodeChange}
                  onKeyDown={handleTextareaKeyDown}
                  onScroll={handleScroll}
                  spellCheck={false}
                  autoCapitalize="off"
                  autoCorrect="off"
                  style={{
                    position: 'absolute',
                    inset: 0,
                    margin: 0,
                    padding: '12px 16px',
                    border: 'none',
                    outline: 'none',
                    resize: 'none',
                    background: 'transparent',
                    color: 'transparent',
                    caretColor: '#58a6ff',
                    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
                    fontSize: '13px',
                    lineHeight: '20px',
                    tabSize: 2,
                    whiteSpace: 'pre',
                    overflowX: 'auto',
                    overflowY: 'auto',
                    boxShadow: 'none',
                    boxSizing: 'border-box',
                    WebkitTextFillColor: 'transparent',
                  }}
                />
              </div>
            </>
          )}
        </div>
      </div>

      {/* Status bar */}
      <div
        className="vb-flex vb-items-center vb-justify-between vb-px-3 vb-flex-shrink-0"
        style={{
          height: '24px',
          background: '#161b22',
          borderTop: '1px solid #30363d',
          fontSize: '11px',
          color: '#8b949e',
          fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
        }}
      >
        <div className="vb-flex vb-items-center vb-gap-3">
          {activeFile && (
            <>
              <span>Ln {lineCount}</span>
              <span>UTF-8</span>
              <span>{getFileIcon(activeFile.path)}</span>
            </>
          )}
        </div>
        <div className="vb-flex vb-items-center vb-gap-3">
          <span>Spaces: 2</span>
          {files.length > 0 && <span>{files.length} files</span>}
        </div>
      </div>
    </div>
  );
}
