/**
 * ChatPanel — Container component for the chat interface.
 *
 * Arranges the MessageList (scrollable, takes all available space)
 * and InputArea (fixed at bottom) in a flex column layout.
 */

import { MessageList } from './MessageList';
import { InputArea } from './InputArea';

export function ChatPanel() {
  return (
    <div className="vb-flex vb-flex-col vb-h-full vb-bg-slate-50">
      {/* Messages — fills all available space */}
      <MessageList />

      {/* Input — stays at the bottom */}
      <InputArea />
    </div>
  );
}
