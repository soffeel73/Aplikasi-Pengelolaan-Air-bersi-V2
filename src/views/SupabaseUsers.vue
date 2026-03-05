<template>
  <div class="min-h-screen bg-slate-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-5xl mx-auto">
      <div class="mb-8 flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">System Monitoring</h1>
          <p class="mt-2 text-slate-600 font-medium">Data real-time dari Supabase Cloud Database</p>
        </div>
        <router-link 
          to="/" 
          class="px-4 py-2 bg-white border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors shadow-sm font-medium"
        >
          ← Kembali ke Landing
        </router-link>
      </div>

      <UserList />
      
      <div class="mt-8 p-6 bg-blue-600 rounded-2xl shadow-xl text-white overflow-hidden relative">
        <div class="relative z-10">
          <h3 class="text-xl font-bold mb-2">💡 Tips Deployment Vercel</h3>
          <ul class="space-y-2 opacity-90 text-sm list-disc pl-5">
            <li>Pastikan sudah menambahkan <code class="bg-blue-700 px-1 rounded">VITE_SUPABASE_URL</code> di Environment Variables Vercel.</li>
            <li>Pastikan sudah menambahkan <code class="bg-blue-700 px-1 rounded">VITE_SUPABASE_ANON_KEY</code> di Environment Variables Vercel.</li>
            <li>Lakukan <strong>Redeploy</strong> jika variabel baru ditambahkan setelah proses build pertama.</li>
          </ul>
        </div>
        <!-- Decorative abstract shape -->
        <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-white/10 rounded-full blur-3xl"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import UserList from '../components/UserList.vue'
</script>
