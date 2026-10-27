import { router, usePage } from '@inertiajs/vue3'
export const useRouter = () => ({ push: (url) => router.visit(url) })
export const useRoute = () => ({ get path() { return usePage().url.split('?')[0] } })
