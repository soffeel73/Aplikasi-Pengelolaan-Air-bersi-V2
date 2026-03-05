<template>
  <div class="p-6 bg-white rounded-xl shadow-lg border border-slate-200">
    <h2 class="text-2xl font-bold text-slate-800 mb-6 flex items-center gap-2">
      <span class="p-2 bg-blue-100 rounded-lg">👥</span>
      Daftar Pengguna (Supabase)
    </h2>

    <div v-if="loading" class="flex flex-col items-center justify-center py-12 gap-4">
      <div class="w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
      <p class="text-slate-500 font-medium">Sedang mengambil data...</p>
    </div>

    <div v-else-if="error" class="p-4 bg-red-50 border border-red-200 text-red-600 rounded-lg flex items-center gap-3">
      <span class="text-xl">⚠️</span>
      <p>{{ error }}</p>
    </div>

    <div v-else-if="users.length === 0" class="text-center py-12 text-slate-500 italic">
      Tidak ada data pengguna ditemukan.
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div 
        v-for="user in users" 
        :key="user.id"
        class="group p-4 bg-slate-50 hover:bg-white hover:shadow-md border border-transparent hover:border-blue-200 rounded-xl transition-all duration-300"
      >
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center text-white font-bold text-lg shadow-sm group-hover:scale-110 transition-transform">
            {{ user.name?.charAt(0).toUpperCase() || 'U' }}
          </div>
          <div>
            <h3 class="font-bold text-slate-800 group-hover:text-blue-600 transition-colors">{{ user.name }}</h3>
            <p class="text-sm text-slate-500 flex items-center gap-1">
              📧 {{ user.email }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import supabase from '../lib/supabase'

const users = ref([])
const loading = ref(true)
const error = ref(null)

const fetchUsers = async () => {
  try {
    loading.value = true
    const { data, error: supabaseError } = await supabase
      .from('users')
      .select('*')
      .order('name', { ascending: true })

    if (supabaseError) throw supabaseError
    users.value = data
  } catch (err) {
    console.error('Error fetching users:', err)
    error.value = 'Gagal memuat data: ' + err.message
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<style scoped>
/* Gradient and animations are handled via Tailwind classes in the template */
</style>
