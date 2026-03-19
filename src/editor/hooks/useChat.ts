/**
 * useChat — React hook for chat-related editor state.
 *
 * Selects only the chat-relevant slices from the Zustand store so that
 * components using this hook only re-render when chat state changes,
 * not when unrelated slices (viewport size, theme versions, etc.) update.
 */

import { useShallow } from 'zustand/react/shallow';
import { useEditorStore } from '@/editor/store/editorStore';
import type { Attachment, Message } from '@/editor/types';

export interface UseChatReturn {
  /** All messages in the current session (oldest first). */
  messages: Message[];
  /** Whether the AI is currently streaming a response. */
  isStreaming: boolean;
  /** Accumulated text content of the response being streamed. */
  streamingContent: string;
  /** The current error message, or null. */
  error: string | null;
  /** The currently active session id, or null if no session. */
  currentSessionId: number | null;

  /** Send a user message (optionally with image / Figma attachments). */
  sendMessage: (content: string, attachments?: Attachment[]) => Promise<void>;
  /** Delete all messages in the current session. */
  clearChat: () => Promise<void>;
  /** Abort the in-flight streaming response. */
  stopStreaming: () => void;
  /** Dismiss the current error banner. */
  clearError: () => void;
}

export function useChat(): UseChatReturn {
  const messages = useEditorStore((s) => s.messages);
  const isStreaming = useEditorStore((s) => s.isStreaming);
  const streamingContent = useEditorStore((s) => s.streamingContent);
  const error = useEditorStore((s) => s.error);
  const currentSessionId = useEditorStore((s) => s.currentSessionId);

  // Actions are stable references (defined once in the store), so selecting
  // them individually avoids unnecessary shallow-compare overhead.
  const sendMessage = useEditorStore((s) => s.sendMessage);
  const clearChat = useEditorStore((s) => s.clearChat);
  const stopStreaming = useEditorStore((s) => s.stopStreaming);
  const clearError = useEditorStore((s) => s.clearError);

  return {
    messages,
    isStreaming,
    streamingContent,
    error,
    currentSessionId,
    sendMessage,
    clearChat,
    stopStreaming,
    clearError,
  };
}

/**
 * useSessions — Convenience hook for session list and management.
 */
export interface UseSessionsReturn {
  sessions: ReturnType<typeof useEditorStore.getState>['sessions'];
  currentSessionId: number | null;
  loadSessions: () => Promise<void>;
  createSession: (name?: string, themeSlug?: string) => Promise<any>;
  switchSession: (sessionId: number) => Promise<void>;
}

export function useSessions(): UseSessionsReturn {
  return useEditorStore(
    useShallow((s) => ({
      sessions: s.sessions,
      currentSessionId: s.currentSessionId,
      loadSessions: s.loadSessions,
      createSession: s.createSession,
      switchSession: s.switchSession,
    })),
  );
}

/**
 * useModelSelection — Hook for AI model picker state.
 */
export interface UseModelSelectionReturn {
  selectedModel: string;
  availableModels: ReturnType<typeof useEditorStore.getState>['availableModels'];
  loadModels: () => Promise<void>;
  setModel: (modelId: string) => Promise<void>;
}

export function useModelSelection(): UseModelSelectionReturn {
  return useEditorStore(
    useShallow((s) => ({
      selectedModel: s.selectedModel,
      availableModels: s.availableModels,
      loadModels: s.loadModels,
      setModel: s.setModel,
    })),
  );
}
