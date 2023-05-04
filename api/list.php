<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>EB Manage</title>
    <script src="https://cdn.bootcss.com/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/vue/2.5.16/vue.min.js"></script>
    <script src="https://cdn.bootcdn.net/ajax/libs/vue-resource/1.5.1/vue-resource.min.js"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/element-ui/2.15.13/theme-chalk/index.css" rel="stylesheet">
    <script src="https://cdn.bootcdn.net/ajax/libs/element-ui/2.15.13/index.js"></script>
</head>
<body>
    <div id="app">
        <div style="padding: 16px">
            <el-row :gutter="20">
                <el-col :span="20">
                    <el-form :inline="true" :model="formSearch" class="demo-form-inline" size="small">
                        <el-form-item label="Title">
                            <el-input v-model="formSearch.tf" placeholder="Title"></el-input>
                        </el-form-item>
                        <el-form-item label="Tags">
                            <el-input v-model="formSearch.tbf" placeholder="Tags"></el-input>
                        </el-form-item>
                        <el-form-item label="Status">
                            <el-select v-model="formSearch.sf" clearable placeholder="All">
                                <el-option label="Draft" value="0"></el-option>
                                <el-option label="Published" value="1"></el-option>
                            </el-select>
                        </el-form-item>
                        <el-form-item>
                            <el-button type="primary" @click="onSearchSubmit">Search</el-button>
                        </el-form-item>
                    </el-form>
                </el-col>
                <el-col :span="4" style="display: flex;justify-content: flex-end">
                    <el-button @click="handleEdit(0)" type="primary" icon="el-icon-edit" size="small">Add</el-button>
                </el-col>
            </el-row>

            <template v-if="loading">
                <el-skeleton  :rows="6" animated />
            </template>
            <template v-else>
                <el-table
                        :data="tableData"
                        stripe
                        style="width: 100%">
                    <el-table-column label="ID" prop="id" width="350"></el-table-column>
                    <el-table-column label="Title" prop="title" width=""></el-table-column>
                    <el-table-column label="Tags" prop="tags" width=""></el-table-column>
                    <el-table-column label="Create time" prop="created_at" width="180"></el-table-column>
                    <el-table-column label="Update time" prop="updated_at" width="180"></el-table-column>
                    <el-table-column label="Status" prop="status" width="120">
                        <template slot-scope="scope">
                            <el-tag v-if="scope.row.status === 1" type="success" effect="plain">Published</el-tag>
                            <el-tag v-else type="danger" effect="plain">Draft</el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="Operate" width="180">
                        <template slot-scope="scope">
                            <el-button
                                    size="small"
                                    @click="handleEdit(scope.row.id)">Edit</el-button>
                            <el-button
                                    size="small"
                                    type="danger"
                                    @click="handleDelete(scope.row.id)">Delete</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <div style="margin-top: 20px">
                    <el-button-group>
                        <el-button @click="firstPage" size="small" icon="el-icon-d-arrow-left">First page</el-button>
                        <el-button @click="nextPage" v-if="pageSearch.next_cursor" type="primary" size="small">Next page<i class="el-icon-arrow-right el-icon--right"></i></el-button>
                    </el-button-group>
                </div>
            </template>
        </div>
    </div>
</body>
</html>
<script type="module">
    new Vue({
        el: "#app",
        data() {
            return {
                apiUrl: '<?= $_ENV['API_URL'] ?? ''; ?>',
                loading: true,
                tableData: [],
                pageSearch: {
                    next_cursor: null
                },
                formSearch: {
                    backend: true,
                    m: 'rows',
                    size: 20,
                    tf: '',
                    tbf: '',
                    sf: '',
                    current: null,
                },
            };
        },
        created() {
            this.getArticleList(this.formSearch)
        },
        methods: {
            firstPage() {
                this.pageSearch.next_cursor = null
                this.onSearchSubmit()
            },
            nextPage() {
                this.formSearch.current = this.pageSearch.next_cursor
                this.getArticleList(this.formSearch)
            },
            onSearchSubmit() {
                this.formSearch.current = null
                this.getArticleList(this.formSearch)
            },
            handleEdit(id) {
                if (id) {
                    window.location = '/api/detail?id='+id
                } else {
                    window.location = '/api/detail'
                }
            },
            handleDelete(id) {
                this.$confirm('Are you sure you want to delete?', 'Tips', {
                    confirmButtonText: 'Confirm',
                    cancelButtonText: 'Cancel',
                    type: 'warning'
                }).then(() => {
                    if(id){
                        let EB_TOKEN = this.checkToken()
                        if (!EB_TOKEN) return false
                        this.$http.post(this.apiUrl, {m:'delete',pid:id},{emulateJSON:true,headers:{'token':EB_TOKEN}}).then(res=>{
                            let code = res.body.code
                            if (code === 200) {
                                this.$message.success(res.body.msg)
                                this.getArticleList(this.formSearch)
                                return false
                            }
                            this.$message.error(res.body.msg)
                        })
                    }
                }).catch(() => {
                    // cancel
                })
            },
            checkToken() {
                let EB_TOKEN = localStorage.getItem('EB_TOKEN')
                if (!EB_TOKEN) {
                    this.$prompt('Please enter the operation key', 'Tips', {
                        confirmButtonText: 'Confirm',
                        cancelButtonText: 'Cancel',
                        inputType: 'password',
                    }).then(({ value }) => {
                        this.$http.post(this.apiUrl, {m:'login',operate_key:value},{emulateJSON:true}).then(res=>{
                            let code = res.body.code
                            if (code !== 200) {
                                this.$message.error(res.body.msg)
                                return false
                            }
                            localStorage.setItem('EB_TOKEN',res.body.data.token)
                            this.$notify.success({
                              title: 'Success',
                              message: 'Operation key verification passed.'
                            })
                            return res.body.data.token
                        })
                    }).catch(() => {
                        return false
                    });
                    return false
                }
                return EB_TOKEN
            },
            getArticleList(params){
                if (!params) {
                    this.$message.error('Failed to obtain request parameters!')
                    return false
                }
                const loading = this.$loading({
                    lock: true,
                    text: 'Loading',
                    spinner: 'el-icon-loading',
                    background: 'rgba(0, 0, 0, 0.7)'
                });
                this.$http.get(this.apiUrl,{params:params}).then(res => {
                    loading.close();
                    let code = res.body.code
                    if (code === 200) {
                        this.tableData = res.body.data.list
                        this.pageSearch.next_cursor = res.body.data.next_cursor ?? null
                    }
                    this.loading = false
                })
            },
        },
    })
</script>
