/**
 * App.tsx — Root component for the WPVibe theme editor.
 *
 * Full-viewport layout with:
 * - Top bar: logo, model selector, session management, new theme button
 * - Main area: ChatPanel (left ~40%) | PreviewPanel (right, flex-1)
 */

import { useEffect, useState, useRef, useCallback } from 'react';
import { useEditorStore } from '@/editor/store/editorStore';
import { useChat } from '@/editor/hooks/useChat';
import { useSessions, useModelSelection } from '@/editor/hooks/useChat';
import { ChatPanel } from '@/editor/components/ChatPanel/ChatPanel';
import { PreviewPanel } from '@/editor/components/PreviewPanel/PreviewPanel';
import { NewThemeModal } from '@/editor/components/Modals/NewThemeModal';
import type { Session } from '@/editor/types';

import '@/editor/styles/editor.css';

// ---------------------------------------------------------------------------
// SessionsDropdown sub-component
// ---------------------------------------------------------------------------

interface SessionsDropdownProps {
  sessions: Session[];
  currentSessionId: number | null;
  onSelect: (sessionId: number) => void;
  onClose: () => void;
}

function SessionsDropdown({
  sessions,
  currentSessionId,
  onSelect,
  onClose,
}: SessionsDropdownProps) {
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(e.target as Node)
      ) {
        onClose();
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [onClose]);

  if (sessions.length === 0) {
    return (
      <div ref={dropdownRef} className="vb-sessions-dropdown vb-p-4">
        <p className="vb-text-sm vb-text-slate-400">No sessions yet.</p>
      </div>
    );
  }

  return (
    <div ref={dropdownRef} className="vb-sessions-dropdown">
      {sessions.map((session) => {
        const isActive = session.id === currentSessionId;
        // MySQL datetime "2026-03-05 08:54:45" needs the T separator for reliable parsing.
        const raw = session.createdAt?.replace(' ', 'T');
        const date = raw ? new Date(raw) : null;
        const dateStr =
          date && !isNaN(date.getTime())
            ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
            : '';

        return (
          <button
            key={session.id}
            type="button"
            onClick={() => {
              onSelect(session.id);
              onClose();
            }}
            className={`vb-w-full vb-text-left vb-px-4 vb-py-3 vb-border-b vb-border-slate-100 last:vb-border-b-0 vb-transition-colors hover:vb-bg-slate-50 ${
              isActive ? 'vb-bg-indigo-50' : ''
            }`}
          >
            <div className="vb-flex vb-items-center vb-justify-between vb-gap-2">
              <span
                className={`vb-text-sm vb-truncate ${
                  isActive
                    ? 'vb-font-semibold vb-text-indigo-700'
                    : 'vb-font-medium vb-text-slate-700'
                }`}
              >
                {session.sessionName}
              </span>
              <span className="vb-text-xs vb-text-slate-400 vb-whitespace-nowrap">
                {dateStr}
              </span>
            </div>
            {session.modelUsed && (
              <span className="vb-text-xs vb-text-slate-400">
                {session.modelUsed}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

// ---------------------------------------------------------------------------
// App component
// ---------------------------------------------------------------------------

interface Announcement {
  id: string;
  title: string;
  content: string;
  type: 'INFO' | 'WARNING' | 'UPDATE';
}

export function App() {
  const notification = useEditorStore((s) => s.notification);
  const clearNotification = useEditorStore((s) => s.clearNotification);
  const { error, clearError } = useChat();
  const { sessions, currentSessionId, loadSessions, createSession, switchSession } =
    useSessions();
  const { loadModels } = useModelSelection();

  const [sessionsOpen, setSessionsOpen] = useState(false);
  const [showNewThemeModal, setShowNewThemeModal] = useState(false);
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [dismissedIds, setDismissedIds] = useState<Set<string>>(new Set());

  // On mount: load sessions and models, create a session if none exist
  useEffect(() => {
    async function init() {
      await Promise.all([loadSessions(), loadModels()]);

      // After sessions are loaded, check if we need to create one.
      // We use getState() here to read the very latest state.
      const state = useEditorStore.getState();
      if (state.sessions.length === 0) {
        setShowNewThemeModal(true);
      } else if (state.currentSessionId === null) {
        await state.switchSession(state.sessions[0].id);
      }
    }
    init();

    // Fetch portal announcements.
    fetch(
      `${((window as any).wpvibeData?.restUrl || '/wp-json/wpvibe/v1/').replace(/\/+$/, '')}/announcements`,
      {
        headers: {
          'X-WP-Nonce': (window as any).wpvibeData?.nonce || '',
        },
      }
    )
      .then((r) => r.json())
      .then((data) => {
        if (data?.announcements) setAnnouncements(data.announcements);
      })
      .catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleNewTheme = useCallback(async (name: string, themeSlug?: string) => {
    setShowNewThemeModal(false);
    await createSession(name, themeSlug);
  }, [createSession]);

  const handleSessionSelect = async (sessionId: number) => {
    await switchSession(sessionId);
  };

  // Find current session name for the top bar
  const currentSession = sessions.find((s) => s.id === currentSessionId);

  return (
    <div className="vb-flex vb-flex-col vb-h-screen vb-w-screen vb-overflow-hidden vb-bg-slate-50">
      {/* ── Top Bar ─────────────────────────────────────────────────────── */}
      <header className="vb-flex vb-items-center vb-justify-between vb-h-12 vb-px-4 vb-bg-slate-800 vb-text-white vb-flex-shrink-0 vb-border-b vb-border-slate-700">
        {/* Left: Logo + session selector */}
        <div className="vb-flex vb-items-center vb-gap-4">
          {/* Logo */}
          <div className="vb-flex vb-items-center vb-gap-2">
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
              className="vb-text-indigo-400"
            >
              <path d="M12 2L2 7l10 5 10-5-10-5z" />
              <path d="M2 17l10 5 10-5" />
              <path d="M2 12l10 5 10-5" />
            </svg>
            <span className="vb-text-sm vb-font-bold vb-tracking-tight">
              WP Vibe
            </span>
          </div>

          {/* Session selector */}
          <div className="vb-relative">
            <button
              type="button"
              onClick={() => setSessionsOpen(!sessionsOpen)}
              className="vb-flex vb-items-center vb-gap-1.5 vb-px-3 vb-py-1 vb-rounded-lg vb-text-xs vb-font-medium vb-text-slate-300 vb-bg-slate-700 vb-border vb-border-slate-600 vb-transition-colors hover:vb-bg-slate-600 hover:vb-text-white"
            >
              <span className="vb-max-w-[160px] vb-truncate">
                {currentSession?.sessionName || 'Select session'}
              </span>
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="12"
                height="12"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
              >
                <polyline points="6 9 12 15 18 9" />
              </svg>
            </button>

            {sessionsOpen && (
              <SessionsDropdown
                sessions={sessions}
                currentSessionId={currentSessionId}
                onSelect={handleSessionSelect}
                onClose={() => setSessionsOpen(false)}
              />
            )}
          </div>
        </div>

        {/* Right: New Theme button + settings */}
        <div className="vb-flex vb-items-center vb-gap-2">
          <button
            type="button"
            onClick={() => setShowNewThemeModal(true)}
            className="vb-flex vb-items-center vb-gap-1.5 vb-px-3 vb-py-1.5 vb-rounded-lg vb-text-xs vb-font-medium vb-text-white vb-bg-indigo-600 vb-transition-colors hover:vb-bg-indigo-500 active:vb-bg-indigo-700"
          >
            {/* Plus icon */}
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <line x1="12" y1="5" x2="12" y2="19" />
              <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            New Theme
          </button>

          {/* Settings link */}
          {window.wpvibeData?.adminUrl && (
            <a
              href={`${window.wpvibeData.adminUrl}admin.php?page=wpvibe-settings`}
              className="vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-lg vb-text-slate-400 vb-transition-colors hover:vb-text-white hover:vb-bg-slate-700"
              title="Settings"
            >
              {/* Gear icon */}
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
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.32 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
              </svg>
            </a>
          )}
        </div>
      </header>

      {/* ── Announcements Banner ──────────────────────────────────────── */}
      {announcements
        .filter((a) => !dismissedIds.has(a.id))
        .map((a) => (
          <div
            key={a.id}
            className={`vb-flex vb-items-center vb-justify-between vb-px-4 vb-py-2 vb-text-sm vb-flex-shrink-0 vb-border-b ${
              a.type === 'WARNING'
                ? 'vb-bg-yellow-50 vb-border-yellow-200 vb-text-yellow-800'
                : a.type === 'UPDATE'
                  ? 'vb-bg-blue-50 vb-border-blue-200 vb-text-blue-800'
                  : 'vb-bg-slate-50 vb-border-slate-200 vb-text-slate-700'
            }`}
          >
            <span>
              <strong>{a.title}</strong> — {a.content}
            </span>
            <button
              onClick={() => setDismissedIds((prev) => new Set([...prev, a.id]))}
              className="vb-ml-2 vb-text-current vb-opacity-60 hover:vb-opacity-100"
            >
              &times;
            </button>
          </div>
        ))}

      {/* ── Error Banner ────────────────────────────────────────────────── */}
      {error && (
        <div className="vb-error-banner vb-flex vb-items-center vb-justify-between vb-px-4 vb-py-2 vb-bg-red-50 vb-border-b vb-border-red-200 vb-text-sm vb-text-red-700 vb-flex-shrink-0">
          <div className="vb-flex vb-items-center vb-gap-2">
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
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <span>{error}</span>
          </div>
          <button
            type="button"
            onClick={clearError}
            className="vb-text-red-500 vb-transition-colors hover:vb-text-red-700 vb-font-medium"
            aria-label="Dismiss error"
          >
            {/* X icon */}
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
          </button>
        </div>
      )}

      {/* ── Main Editor Area ────────────────────────────────────────────── */}
      <div className="vb-flex vb-flex-1 vb-overflow-hidden">
        {/* Chat Panel — left side, ~40% width, min 480px */}
        <div
          className="vb-flex-shrink-0 vb-overflow-hidden"
          style={{ width: '28%', minWidth: '320px', maxWidth: '420px' }}
        >
          <ChatPanel />
        </div>

        {/* Divider */}
        <div className="vb-w-px vb-bg-slate-200 vb-flex-shrink-0" />

        {/* Preview Panel — right side, takes remaining space */}
        <div className="vb-flex-1 vb-overflow-hidden">
          <PreviewPanel />
        </div>
      </div>

      <NewThemeModal
        isOpen={showNewThemeModal}
        onClose={() => setShowNewThemeModal(false)}
        onCreate={handleNewTheme}
      />

      {/* ── Toast Notification ─────────────────────────────────────────── */}
      {notification && (
        <div
          className={`vb-fixed vb-bottom-6 vb-right-6 vb-z-[99999] vb-flex vb-items-center vb-gap-3 vb-px-4 vb-py-3 vb-rounded-lg vb-shadow-lg vb-border vb-text-sm vb-font-medium vb-animate-slide-up ${
            notification.type === 'success'
              ? 'vb-bg-emerald-50 vb-border-emerald-200 vb-text-emerald-800'
              : 'vb-bg-red-50 vb-border-red-200 vb-text-red-800'
          }`}
        >
          {notification.type === 'success' ? (
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-emerald-500 vb-flex-shrink-0">
              <path d="M22 11.08V12a10 10 0 11-5.93-9.14" />
              <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
          ) : (
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-red-500 vb-flex-shrink-0">
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          )}
          <span>{notification.message}</span>
          <button
            type="button"
            onClick={clearNotification}
            className="vb-ml-2 vb-opacity-60 hover:vb-opacity-100"
            aria-label="Dismiss"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
      )}
    </div>
  );
}
