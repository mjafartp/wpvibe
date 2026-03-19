/**
 * Zustand store for the WPVibe theme editor.
 *
 * Manages sessions, chat messages, streaming state, model selection,
 * and theme preview state for the split-screen editor interface.
 */

import { create } from 'zustand';

import type {
  Attachment,
  Message,
  Model,
  SelectedSection,
  Session,
  ThemeVersion,
} from '@/editor/types';
import {
  applyTheme as apiApplyTheme,
  exportTheme as apiExportTheme,
  clearHistory,
  createSession as apiCreateSession,
  getHistory,
  getModels,
  getSessions,
  getThemeVersions,
  restoreVersion,
  saveSettings,
  securityFix,
  securityScan,
  streamChat,
} from '@/editor/api/wordpress';
import type {
  SecurityFinding,
  SecurityScanResult,
} from '@/editor/api/wordpress';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type ViewportSize = 'mobile' | 'tablet' | 'desktop';

export type StreamingPhase = 'thinking' | 'generating' | 'building' | 'done';

export interface EditorState {
  // -- Sessions ---------------------------------------------------------------
  sessions: Session[];
  currentSessionId: number | null;

  // -- Messages ---------------------------------------------------------------
  messages: Message[];
  isStreaming: boolean;
  /** Accumulated text content while a response is being streamed. */
  streamingContent: string;
  /** Current streaming phase for UI indicators. */
  streamingPhase: StreamingPhase | null;

  // -- Model ------------------------------------------------------------------
  selectedModel: string;
  availableModels: Model[];

  // -- Preview ----------------------------------------------------------------
  previewUrl: string;
  viewportSize: ViewportSize;
  themeVersions: ThemeVersion[];
  currentVersionIndex: number;
  isApplying: boolean;
  isExporting: boolean;

  // -- Section Selection ------------------------------------------------------
  selectedSection: SelectedSection | null;
  selectModeActive: boolean;

  // -- Notifications -----------------------------------------------------------
  notification: { message: string; type: 'success' | 'error' } | null;

  // -- Error ------------------------------------------------------------------
  error: string | null;

  // -- Security Scan ----------------------------------------------------------
  isScanning: boolean;
  isFixing: boolean;
  scanResult: SecurityScanResult | null;
  pendingAction: 'apply' | 'export' | null;
  scannedVersionId: number | null;
  /** Version ID that last passed a security scan (safe or user-fixed). */
  lastPassedScanVersionId: number | null;

  // -- Actions ----------------------------------------------------------------
  loadSessions: () => Promise<void>;
  createSession: (name?: string, themeSlug?: string) => Promise<Session>;
  switchSession: (sessionId: number) => Promise<void>;
  loadHistory: (sessionId: number, beforeId?: number) => Promise<void>;
  sendMessage: (content: string, attachments?: Attachment[]) => Promise<void>;
  clearChat: () => Promise<void>;
  loadModels: () => Promise<void>;
  setModel: (modelId: string) => Promise<void>;
  setViewportSize: (size: ViewportSize) => void;
  loadThemeVersions: (sessionId: number) => Promise<void>;
  undo: () => Promise<void>;
  redo: () => Promise<void>;
  goToVersion: (index: number) => Promise<void>;
  applyTheme: (versionId?: number) => Promise<void>;
  exportTheme: (versionId?: number) => Promise<void>;
  fixSecurityIssues: () => Promise<void>;
  dismissScanResult: () => void;
  proceedWithoutFix: () => Promise<void>;
  setSelectedSection: (section: SelectedSection | null) => void;
  setSelectModeActive: (active: boolean) => void;
  clearError: () => void;
  clearNotification: () => void;
  stopStreaming: () => void;
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/** Counter for temporary (optimistic) message IDs. */
let tempIdCounter = 0;
function nextTempId(): number {
  tempIdCounter -= 1;
  return tempIdCounter;
}

/**
 * AbortController reference for the in-flight streaming request.
 * Stored outside the store so it is never serialised / proxied.
 */
let activeAbortController: AbortController | null = null;

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

export const useEditorStore = create<EditorState>()((set, get) => ({
  // -- Initial state ----------------------------------------------------------
  sessions: [],
  currentSessionId: null,

  messages: [],
  isStreaming: false,
  streamingContent: '',
  streamingPhase: null,

  selectedModel: window.wpvibeData?.selectedModel ?? '',
  availableModels: [],

  previewUrl: '',
  viewportSize: 'desktop',
  themeVersions: [],
  currentVersionIndex: -1,
  isApplying: false,
  isExporting: false,

  selectedSection: null,
  selectModeActive: false,

  notification: null,
  error: null,

  isScanning: false,
  isFixing: false,
  scanResult: null,
  pendingAction: null,
  scannedVersionId: null,
  lastPassedScanVersionId: null,

  // -- Session actions --------------------------------------------------------

  loadSessions: async () => {
    try {
      const sessions = await getSessions();
      set({ sessions });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  createSession: async (name?: string, themeSlug?: string) => {
    try {
      const session = await apiCreateSession(name, themeSlug);
      set((state) => ({
        sessions: [session, ...state.sessions],
        currentSessionId: session.id,
        messages: [],
        streamingContent: '',
        previewUrl: '',
        themeVersions: [],
        currentVersionIndex: -1,
        error: null,
      }));

      // If an existing theme was imported, load its version + preview.
      if (themeSlug) {
        await get().loadThemeVersions(session.id);
      }

      return session;
    } catch (err) {
      set({ error: errorMessage(err) });
      throw err;
    }
  },

  switchSession: async (sessionId: number) => {
    // Abort any active stream before switching.
    get().stopStreaming();

    set({
      currentSessionId: sessionId,
      messages: [],
      streamingContent: '',
      previewUrl: '',
      themeVersions: [],
      currentVersionIndex: -1,
      error: null,
    });

    await get().loadHistory(sessionId);
    await get().loadThemeVersions(sessionId);
  },

  loadHistory: async (sessionId: number, beforeId?: number) => {
    try {
      const { messages, session } = await getHistory(sessionId, beforeId);
      // Parse assistant messages that contain raw JSON from the AI.
      const parsed = messages.map((msg) => {
        if (msg.role === 'assistant') {
          return { ...msg, content: extractDisplayMessage(msg.content) };
        }
        return msg;
      });
      if (beforeId !== undefined) {
        // Paginating: prepend older messages.
        set((state) => ({
          messages: [...parsed, ...state.messages],
        }));
      } else {
        set({
          messages: parsed,
          currentSessionId: session.id,
        });
      }
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  // -- Chat actions -----------------------------------------------------------

  sendMessage: async (content: string, attachments?: Attachment[]) => {
    const { currentSessionId, isStreaming } = get();

    if (isStreaming) return; // Prevent overlapping streams.

    // Auto-create a session if none is active.
    let sessionId = currentSessionId;
    if (sessionId === null) {
      try {
        const session = await get().createSession();
        sessionId = session.id;
      } catch {
        return; // createSession already set the error.
      }
    }

    // Optimistically append the user message.
    const userMessage: Message = {
      id: nextTempId(),
      sessionId: sessionId!,
      role: 'user',
      content,
      attachments,
      createdAt: new Date().toISOString(),
    };

    const abortController = new AbortController();
    activeAbortController = abortController;

    set((state) => ({
      messages: [...state.messages, userMessage],
      isStreaming: true,
      streamingContent: '',
      streamingPhase: 'thinking',
      error: null,
    }));

    let accumulatedContent = '';
    let finalMessageId: number | undefined;
    let finalUserMessageId: number | undefined;
    let receivedThemeUpdate = false;

    try {
      const stream = streamChat({
        sessionId: sessionId!,
        message: content,
        attachments,
        signal: abortController.signal,
      });

      for await (const chunk of stream) {
        // Bail early if the stream was aborted between yields.
        if (abortController.signal.aborted) break;

        switch (chunk.type) {
          case 'text_delta': {
            accumulatedContent += chunk.content ?? '';
            // During streaming, show the phase indicator — not raw JSON.
            // Update phase based on content length progress.
            const phase: StreamingPhase =
              accumulatedContent.length < 100 ? 'thinking' : 'generating';
            set({ streamingContent: accumulatedContent, streamingPhase: phase });
            break;
          }

          case 'theme_update': {
            receivedThemeUpdate = true;
            set({ streamingPhase: 'building' });
            if (chunk.themeUpdate) {
              const newVersion: ThemeVersion = {
                id: chunk.themeUpdate.versionId ?? 0,
                sessionId: sessionId!,
                versionNumber: chunk.themeUpdate.versionNumber ?? 1,
                themeSlug: chunk.themeUpdate.themeSlug ?? '',
                filesSnapshot: [],
                messageId: 0,
                previewUrl: chunk.themeUpdate.previewUrl ?? '',
                createdAt: new Date().toISOString(),
              };
              set((state) => ({
                themeVersions: [...state.themeVersions, newVersion],
                currentVersionIndex: state.themeVersions.length, // points to new last element
                previewUrl: newVersion.previewUrl,
              }));
            }
            break;
          }

          case 'error': {
            set({
              error: chunk.error ?? 'An unknown error occurred.',
              isStreaming: false,
              streamingContent: '',
              streamingPhase: null,
            });
            activeAbortController = null;
            return;
          }

          case 'done': {
            finalMessageId = chunk.messageId;
            // The server may also return the persisted user message id
            // inside sessionId field (re-used for convenience by the API).
            finalUserMessageId = chunk.sessionId;
            break;
          }
        }
      }
    } catch (err) {
      // AbortError is expected when the user calls stopStreaming().
      if ((err as DOMException)?.name === 'AbortError') {
        // Keep whatever content was accumulated so far.
      } else {
        set({ error: errorMessage(err) });
      }
    }

    // Finalise: create the assistant message from accumulated content.
    if (accumulatedContent) {
      // Try to parse the AI's JSON response to extract the human-readable message.
      const displayContent = extractDisplayMessage(accumulatedContent);

      const assistantMessage: Message = {
        id: finalMessageId ?? nextTempId(),
        sessionId: sessionId!,
        role: 'assistant',
        content: displayContent,
        createdAt: new Date().toISOString(),
      };

      set((state) => {
        // Replace the optimistic user message with one that has a real id,
        // if the server provided it.
        const updatedMessages = state.messages.map((msg) => {
          if (msg.id === userMessage.id && finalUserMessageId !== undefined) {
            return { ...msg, id: finalUserMessageId };
          }
          return msg;
        });

        return {
          messages: [...updatedMessages, assistantMessage],
          isStreaming: false,
          streamingContent: '',
          streamingPhase: null,
        };
      });
    } else {
      // No content was accumulated (e.g. immediate error or abort).
      set({ isStreaming: false, streamingContent: '', streamingPhase: null });
    }

    activeAbortController = null;
  },

  clearChat: async () => {
    const { currentSessionId } = get();
    if (currentSessionId === null) return;

    get().stopStreaming();

    try {
      await clearHistory(currentSessionId);
      set({
        messages: [],
        streamingContent: '',
        previewUrl: '',
        themeVersions: [],
        currentVersionIndex: -1,
        error: null,
      });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  stopStreaming: () => {
    if (activeAbortController) {
      activeAbortController.abort();
      activeAbortController = null;
    }
    // Note: the streaming loop in sendMessage handles the AbortError
    // and will finalise state, so we do NOT set isStreaming here.
    // If the stream already finished, this is a no-op.
  },

  // -- Model actions ----------------------------------------------------------

  loadModels: async () => {
    try {
      const { models, currentModel } = await getModels();

      // If the saved model isn't in the available list (e.g. user switched
      // providers), fall back to the first recommended or first available model.
      let resolvedModel = currentModel;
      const modelIds = models.map((m) => m.id);

      if (!resolvedModel || !modelIds.includes(resolvedModel)) {
        const recommended = models.find((m) => m.recommended);
        resolvedModel = recommended?.id ?? models[0]?.id ?? '';
        // Persist the corrected selection so the backend uses it too.
        if (resolvedModel) {
          await saveSettings({ selected_model: resolvedModel });
        }
      }

      set({ availableModels: models, selectedModel: resolvedModel });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  setModel: async (modelId: string) => {
    set({ selectedModel: modelId });
    try {
      await saveSettings({ selected_model: modelId });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  // -- Preview actions --------------------------------------------------------

  setViewportSize: (size: ViewportSize) => {
    set({ viewportSize: size });
  },

  loadThemeVersions: async (sessionId: number) => {
    try {
      const versions = await getThemeVersions(sessionId);
      const lastIndex = versions.length > 0 ? versions.length - 1 : -1;
      set({
        themeVersions: versions,
        currentVersionIndex: lastIndex,
        previewUrl: lastIndex >= 0 ? (versions[lastIndex].previewUrl ?? '') : '',
      });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  undo: async () => {
    const { currentVersionIndex } = get();
    if (currentVersionIndex > 0) {
      await get().goToVersion(currentVersionIndex - 1);
    }
  },

  redo: async () => {
    const { themeVersions, currentVersionIndex } = get();
    if (currentVersionIndex < themeVersions.length - 1) {
      await get().goToVersion(currentVersionIndex + 1);
    }
  },

  goToVersion: async (index: number) => {
    const { themeVersions, currentSessionId } = get();
    if (index < 0 || index >= themeVersions.length || currentSessionId === null) {
      return;
    }

    set({ currentVersionIndex: index });

    try {
      const result = await restoreVersion(currentSessionId, index);
      set({ previewUrl: result.previewUrl });
    } catch (err) {
      set({ error: errorMessage(err) });
    }
  },

  applyTheme: async (versionId?: number) => {
    const { currentSessionId, isScanning, themeVersions, currentVersionIndex, lastPassedScanVersionId } = get();
    if (!currentSessionId || isScanning) return;

    // Resolve the version ID to scan — use explicit or current version.
    const resolvedVersionId = versionId ?? themeVersions[currentVersionIndex]?.id;

    // Skip scan if this version already passed.
    if (resolvedVersionId && resolvedVersionId === lastPassedScanVersionId) {
      await executeApply(currentSessionId, versionId);
      return;
    }

    set({ isScanning: true, error: null, pendingAction: 'apply' });
    try {
      const result = await securityScan(currentSessionId, versionId);
      if (result.safe) {
        set({ isScanning: false, pendingAction: null, scanResult: null, lastPassedScanVersionId: result.version_id });
        await executeApply(currentSessionId, versionId);
      } else {
        set({ isScanning: false, scanResult: result, scannedVersionId: result.version_id });
      }
    } catch (err) {
      set({ error: errorMessage(err), isScanning: false, pendingAction: null });
    }
  },

  exportTheme: async (versionId?: number) => {
    const { currentSessionId, isScanning, themeVersions, currentVersionIndex, lastPassedScanVersionId } = get();
    if (!currentSessionId || isScanning) return;

    const resolvedVersionId = versionId ?? themeVersions[currentVersionIndex]?.id;

    // Skip scan if this version already passed.
    if (resolvedVersionId && resolvedVersionId === lastPassedScanVersionId) {
      await executeExport(currentSessionId, versionId);
      return;
    }

    set({ isScanning: true, error: null, pendingAction: 'export' });
    try {
      const result = await securityScan(currentSessionId, versionId);
      if (result.safe) {
        set({ isScanning: false, pendingAction: null, scanResult: null, lastPassedScanVersionId: result.version_id });
        await executeExport(currentSessionId, versionId);
      } else {
        set({ isScanning: false, scanResult: result, scannedVersionId: result.version_id });
      }
    } catch (err) {
      set({ error: errorMessage(err), isScanning: false, pendingAction: null });
    }
  },

  fixSecurityIssues: async () => {
    const { currentSessionId, scanResult, scannedVersionId, pendingAction } = get();
    if (!currentSessionId || !scanResult || !scannedVersionId) return;

    set({ isFixing: true, error: null });
    try {
      const result = await securityFix(currentSessionId, scannedVersionId, scanResult.findings);

      set((state) => ({
        themeVersions: [
          ...state.themeVersions,
          {
            id: result.versionId,
            sessionId: currentSessionId,
            versionNumber: result.versionNumber,
            themeSlug: result.themeSlug,
            filesSnapshot: [],
            messageId: 0,
            previewUrl: result.previewUrl,
            createdAt: new Date().toISOString(),
          },
        ],
        currentVersionIndex: state.themeVersions.length,
        previewUrl: result.previewUrl,
        isFixing: false,
        scanResult: null,
        scannedVersionId: null,
        lastPassedScanVersionId: result.versionId,
      }));

      const action = pendingAction;
      set({ pendingAction: null });

      if (action === 'apply') {
        await executeApply(currentSessionId, result.versionId);
      } else if (action === 'export') {
        await executeExport(currentSessionId, result.versionId);
      }
    } catch (err) {
      set({ error: errorMessage(err), isFixing: false });
    }
  },

  dismissScanResult: () => {
    set({ scanResult: null, pendingAction: null, scannedVersionId: null });
  },

  proceedWithoutFix: async () => {
    const { currentSessionId, pendingAction, scannedVersionId } = get();
    set({ scanResult: null, scannedVersionId: null, lastPassedScanVersionId: scannedVersionId });
    if (!currentSessionId) return;
    const action = pendingAction;
    set({ pendingAction: null });
    if (action === 'apply') {
      await executeApply(currentSessionId, scannedVersionId ?? undefined);
    } else if (action === 'export') {
      await executeExport(currentSessionId, scannedVersionId ?? undefined);
    }
  },

  // -- Section Selection actions -----------------------------------------------

  setSelectedSection: (section) => {
    set({ selectedSection: section });
  },

  setSelectModeActive: (active) => {
    set({ selectModeActive: active });
    if (!active) {
      set({ selectedSection: null });
    }
  },

  // -- Error actions ----------------------------------------------------------

  clearError: () => {
    set({ error: null });
  },

  clearNotification: () => {
    set({ notification: null });
  },
}));

// ---------------------------------------------------------------------------
// Standalone helpers for apply/export (called after security scan passes).
// Defined after useEditorStore so they can call setState.
// ---------------------------------------------------------------------------

function showNotification(message: string, type: 'success' | 'error' = 'success') {
  useEditorStore.setState({ notification: { message, type } });
  setTimeout(() => {
    useEditorStore.setState({ notification: null });
  }, 4000);
}

async function executeApply(sessionId: number, versionId?: number): Promise<void> {
  useEditorStore.setState({ isApplying: true, error: null });
  try {
    await apiApplyTheme(sessionId, versionId);
    showNotification('Theme applied successfully! Your site is now using the generated theme.');
  } catch (err) {
    useEditorStore.setState({ error: errorMessage(err) });
  } finally {
    useEditorStore.setState({ isApplying: false });
  }
}

async function executeExport(sessionId: number, versionId?: number): Promise<void> {
  useEditorStore.setState({ isExporting: true, error: null });
  try {
    const result = await apiExportTheme(sessionId, versionId);
    if (result.url) {
      window.open(result.url, '_blank');
    }
    showNotification('Theme exported! Your download should start shortly.');
  } catch (err) {
    useEditorStore.setState({ error: errorMessage(err) });
  } finally {
    useEditorStore.setState({ isExporting: false });
  }
}

// ---------------------------------------------------------------------------
// Utility
// ---------------------------------------------------------------------------

function errorMessage(err: unknown): string {
  if (err instanceof Error) return err.message;
  if (typeof err === 'string') return err;
  return 'An unexpected error occurred.';
}

/**
 * Extract the human-readable message from the AI's JSON response.
 *
 * Uses JSON.parse first, then falls back to regex extraction for
 * truncated responses. Never shows raw JSON/code to the user.
 */
function extractDisplayMessage(raw: string): string {
  let jsonStr = raw.trim();
  if (jsonStr.startsWith('```')) {
    jsonStr = jsonStr.replace(/^```(?:json)?\s*\n?/, '').replace(/\n?```\s*$/, '');
  }

  // Try full JSON parse first.
  try {
    const parsed = JSON.parse(jsonStr);
    if (typeof parsed === 'object' && parsed !== null) {
      return formatParsedResponse(parsed);
    }
  } catch {
    // JSON is likely truncated — use regex extraction below.
  }

  // Regex fallback for truncated JSON.
  return extractWithRegex(jsonStr);
}

/** Format a successfully parsed AI response object into display text. */
function formatParsedResponse(parsed: Record<string, unknown>): string {
  const parts: string[] = [];

  if (parsed.message) {
    parts.push(String(parsed.message));
  }

  if (Array.isArray(parsed.changes_summary) && parsed.changes_summary.length > 0) {
    parts.push('');
    parts.push('**Changes:**');
    for (const change of parsed.changes_summary) {
      parts.push(`- ${change}`);
    }
  }

  if (Array.isArray(parsed.files) && parsed.files.length > 0) {
    const fileNames = parsed.files
      .map((f: Record<string, unknown>) => `\`${f.path}\``)
      .join(', ');
    parts.push('');
    parts.push(`**Files:** ${fileNames}`);
  }

  return parts.length > 0 ? parts.join('\n') : 'Theme generated.';
}

/** Extract message and file paths from truncated JSON using regex. */
function extractWithRegex(text: string): string {
  const parts: string[] = [];

  // Extract "message" value.
  const msgMatch = text.match(/"message"\s*:\s*"((?:[^"\\]|\\.)*)"/s);
  if (msgMatch) {
    try {
      parts.push(JSON.parse('"' + msgMatch[1] + '"'));
    } catch {
      parts.push(msgMatch[1]);
    }
  }

  // Extract "changes_summary" items.
  const csMatch = text.match(/"changes_summary"\s*:\s*\[([\s\S]*?)\]/);
  if (csMatch) {
    const items = csMatch[1].match(/"((?:[^"\\]|\\.)*)"/g);
    if (items && items.length > 0) {
      parts.push('');
      parts.push('**Changes:**');
      for (const item of items) {
        try {
          parts.push(`- ${JSON.parse(item)}`);
        } catch {
          parts.push(`- ${item.replace(/^"|"$/g, '')}`);
        }
      }
    }
  }

  // Extract file paths from "files" array.
  const pathMatches = text.matchAll(/"path"\s*:\s*"((?:[^"\\]|\\.)*)"/g);
  const filePaths: string[] = [];
  for (const m of pathMatches) {
    filePaths.push(m[1]);
  }
  if (filePaths.length > 0) {
    parts.push('');
    parts.push(`**Files:** ${filePaths.map((p) => `\`${p}\``).join(', ')}`);
  }

  if (parts.length > 0) {
    return parts.join('\n');
  }

  // Last resort — don't show raw JSON, show a generic message.
  return 'Theme generated successfully.';
}
