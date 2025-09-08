import { create } from 'zustand';

export interface CacheEvent { type:string; detail:string; ts:number }
interface EventState { events: CacheEvent[]; add:(e:CacheEvent)=>void; }
export const useEventStore = create<EventState>(set=>({
  events: [],
  add: (e)=> set(s=> ({ events: [e, ...s.events].slice(0,200) }))
}));
