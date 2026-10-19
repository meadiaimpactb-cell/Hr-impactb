<template>
  <component :is="icons[name]" :size="size" :stroke-width="stroke" class="ico" :class="{ flip: flipRtl }" aria-hidden="true" />
</template>

<script setup>
/**
 * Central SVG icon set (Lucide — https://lucide.dev, ISC licence).
 * Only the icons listed here are bundled (tree-shaken).
 * Usage: <Icon name="bell" /> · <Icon name="chevron-start" /> flips automatically in RTL.
 */
import {
  ArrowLeft, ArrowRight, Printer, Ban, Bell, Building2, CalendarDays, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronUp,
  CircleAlert, CircleCheck, CircleX, ClipboardCheck, Clock, Copy, Database, Ellipsis, Eye, EyeOff, Columns3, UserRoundX, UserRoundCheck, MailPlus, Download, ExternalLink, FileText, Fingerprint,
  FolderOpen, Hourglass, House, Inbox, Info, KeyRound, Languages, LayoutDashboard, LogOut, MapPin, Menu, Moon, Pencil,
  Plane, Plus, RefreshCw, Repeat, ScrollText, Search, Send, Settings, ShieldCheck, Sun, Trash2, TreePalm, TriangleAlert,
  UserCheck, UserRound, Users, Workflow, X, HeartPulse, Baby, Gem, Flower2, Zap, CircleDollarSign, Briefcase, UserX, TimerOff, Activity, Plug, ListChecks, FileSpreadsheet, Megaphone, Mail, GraduationCap, Gavel, UserMinus, Shield, Kanban, Upload, BarChart3, FileSignature, ClipboardList,
  Award, TrendingUp, ThumbsUp, HandHeart, PartyPopper, Camera, Palette,
} from 'lucide-vue-next'

defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 18 },
  stroke: { type: [Number, String], default: 1.9 },
  flipRtl: { type: Boolean, default: false },
})

const icons = {
  home: House, dashboard: LayoutDashboard, approvals: ClipboardCheck, requests: FileText, user: UserRound, bell: Bell,
  users: Users, clock: Clock, leave: TreePalm, shield: ShieldCheck, building: Building2, database: Database,
  workflow: Workflow, settings: Settings, audit: ScrollText, menu: Menu, plus: Plus, moon: Moon, sun: Sun, logout: LogOut,
  search: Search, download: Download, edit: Pencil, 'arrow-left': ArrowLeft, 'arrow-right': ArrowRight,
  'chevron-left': ChevronLeft, 'chevron-right': ChevronRight, 'chevron-up': ChevronUp, 'chevron-down': ChevronDown,
  x: X, check: Check, 'check-circle': CircleCheck, 'x-circle': CircleX, warning: TriangleAlert, alert: CircleAlert,
  info: Info, ban: Ban, 'map-pin': MapPin, repeat: Repeat, languages: Languages, inbox: Inbox, folder: FolderOpen,
  printer: Printer, calendar: CalendarDays, fingerprint: Fingerprint, trash: Trash2, key: KeyRound, refresh: RefreshCw, copy: Copy, eye: Eye, 'eye-off': EyeOff, more: Ellipsis, columns: Columns3, 'user-off': UserRoundX, 'user-on': UserRoundCheck, 'mail-plus': MailPlus,
  external: ExternalLink, send: Send, hourglass: Hourglass, 'user-check': UserCheck, plane: Plane,
  sick: HeartPulse, maternity: Baby, marriage: Gem, bereavement: Flower2, emergency: Zap, money: CircleDollarSign,
  briefcase: Briefcase, absent: UserX, late: TimerOff, activity: Activity, plug: Plug, lists: ListChecks, sheet: FileSpreadsheet,
  megaphone: Megaphone, mail: Mail, training: GraduationCap, gavel: Gavel, award: Award, trend: TrendingUp, exit: UserMinus, insurance: Shield, kanban: Kanban,
  upload: Upload, chart: BarChart3, signature: FileSignature, report: ClipboardList,
  like: ThumbsUp, thanks: HandHeart, celebrate: PartyPopper, camera: Camera, palette: Palette,
}
</script>
