<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Edit - EB Manage</title>
    <script src="https://cdn.bootcss.com/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/vue@2.5.16/dist/vue.min.js"></script>
    <script src="https://cdn.staticfile.org/vue-resource/1.5.1/vue-resource.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/element-ui/lib/theme-chalk/index.css">
    <script src="https://cdn.jsdelivr.net/npm/element-ui/lib/index.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <link rel="stylesheet" href="https://npm.elemecdn.com/heyiki-cdn/mavon-editor/dist/css/index.css">
    <script src="https://npm.elemecdn.com/heyiki-cdn/mavon-editor/dist/mavon-editor.js"></script>
</head>
<body>
    <div id="app">
        <div style="padding: 16px">
            <el-page-header @back="goBack" content="Edit page" style="margin-bottom: 32px;"></el-page-header>
            <el-form ref="form" :model="form" status-icon label-width="80px">
                <el-form-item
                        label="Title"
                        prop="title"
                        :rules="[{ required: true, message: 'Please complete this required field.'},]"
                >
                    <el-input v-model="form.title" autocomplete="off"></el-input>
                </el-form-item>
                <el-form-item
                        label="Tags"
                        prop="tags"
                        :rules="[{ required: true, message: 'Please complete this required field.'},]"
                >
                    <el-input v-model="form.tags" autocomplete="off"></el-input>
                </el-form-item>
                <el-form-item label="Status">
                    <el-radio-group v-model="form.status">
                      <el-radio :label="0">Draft</el-radio>
                      <el-radio :label="1">Published</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item
                        label="Content"
                        :rules="[{ required: true, message: 'Please complete this required field.'},]"
                >
                    <mavon-editor
                            ref=md
                            language="en"
                            :toolbars="toolbars"
                            v-model="form.content"
                    >
                        <!-- Add a custom button after the left toolbar  -->
                        <template slot="left-toolbar-after">
                            <button type="button" @click="imgLinkDialog" class="op-icon fa fa-mavon-picture-o" aria-hidden="true" title="Picture（ctrl+alt+l）"></button>
                        </template>
                    </mavon-editor>
                </el-form-item>
                <el-form-item>
                    <el-button :loading="btnLoading" type="primary" @click="saveArticle">{{ form.id !== 0 ? 'Save' : 'Add'}}</el-button>
                    <el-button @click="resetForm('form')">Reset</el-button>
                </el-form-item>
            </el-form>

            <!-- markdown Add custom pictures  -->
            <el-dialog
                title="Add picture"
                :visible.sync="mdDialogVisible"
                width="30%"
                center>
                    <el-input v-model="mdImgDesc" placeholder="Picture desc"></el-input>
                    <el-input v-model="mdImgUrl"  placeholder="Picture url" style="margin-top: 20px"></el-input>
                    <span slot="footer" class="dialog-footer">
                        <el-button @click="mdDialogVisible = false;mdImgDesc=mdImgUrl=''">Cancel</el-button>
                        <el-button type="primary" @click="imgLink">Confirm</el-button>
                    </span>
            </el-dialog>
        </div>
    </div>
</body>
</html>
<script>
    Vue.use(window.MavonEditor)
    new Vue({
        el: "#app",
        data() {
            return {
                apiUrl: '<?= $_ENV['API_URL'] ?? ''; ?>',
                btnLoading: false,
                method: 'create',
                form: {
                    pid: 0,
                    tags: '',
                    title: '',
                    content: '',
                    status: 1,
                },
                mdDialogVisible: false,
                mdImgDesc: '',
                mdImgUrl: '',
                toolbars: {
                    bold: true,
                    italic: true,
                    header: true,
                    underline: true,
                    strikethrough: true,
                    mark: true,
                    superscript: true,
                    subscript: true,
                    quote: true,
                    ol: true,
                    ul: true,
                    link: true,
                    imagelink: false,
                    code: true,
                    table: true,
                    fullscreen: true,
                    readmodel: true,
                    htmlcode: true,
                    help: true,
                    /* 1.3.5 */
                    undo: true,
                    redo: true,
                    trash: true,
                    save: false,
                    /* 1.4.2 */
                    navigation: true,
                    /* 2.1.8 */
                    alignleft: false,
                    aligncenter: false,
                    alignright: false,
                    /* 2.2.1 */
                    subfield: true,
                    preview: true,
                },
            };
        },
        created() {
            let id = this.GetQueryString('id')
            if (id !== null) {
                this.method = 'edit'
                this.getArticleDetail(id)
            }
        },
        methods: {
            goBack() {
                window.location = '/api/list'
            },
            GetQueryString(name) {
                let reg = new RegExp("(^|&)" + name + "=([^&]*)(&|$)");
                let r = window.location.search.substr(1).match(reg);
                console.log(r)
                if(r != null) return decodeURI(r[2]);
                return null;
            },
            resetForm(formName) {
                this.$refs[formName].resetFields();
            },
            saveArticle() {
                this.$refs.form.validate((valid) => {
                    if (valid) {
                        let EB_TOKEN = this.checkToken()
                        if (!EB_TOKEN) return false
                        this.btnLoading = true
                        let form = Object.assign({},this.form)
                        form.content = encodeURIComponent(CryptoJS.enc.Base64.stringify(CryptoJS.enc.Utf8.parse(form.content)))
                        form.m = this.method
                        form.status = form.status ? 1 : 0
                        this.$http.post(this.apiUrl,form,{emulateJSON:true,headers:{'token':EB_TOKEN}}).then(res=>{
                            let code = res.body.code
                            if (code === 200) {
                                this.$message.success(res.body.msg)
                            } else {
                                if (code === 403) {
                                    localStorage.removeItem('EB_TOKEN')
                                }
                                this.$message.error(res.body.msg)
                            }
                            this.btnLoading = false
                        })
                    } else {
                        console.log('error submit!!');
                        return false;
                    }
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
            getArticleDetail(id) {
                const loading = this.$loading({
                    lock: true,
                    text: 'Loading',
                    spinner: 'el-icon-loading',
                    background: 'rgba(0, 0, 0, 0.7)'
                });
                this.$http.get(this.apiUrl,{params:{m:"detail",pid:id}}).then(res=>{
                    loading.close();
                    let code = res.body.code
                    if (code === 200) {
                        if (res.body.data.content) {
                            res.body.data.content = CryptoJS.enc.Base64.parse(decodeURIComponent(res.body.data.content)).toString(CryptoJS.enc.Utf8)
                        }
                        this.form = res.body.data
                    }
                })
            },
            imgLinkDialog() {
                this.mdDialogVisible = true
            },
            // Customize markdown to insert pictures
            imgLink() {
                let linkImg = '!['+this.mdImgDesc+']('+this.mdImgUrl+')'
                let textarea = document.getElementsByClassName("auto-textarea-input")[0];
                let posStart = textarea.selectionStart;
                let posEnd = textarea.selectionEnd;
                let subStart = this.$refs.md.d_value.substring(0, posStart);
                let subEnd = this.$refs.md.d_value.substring(posEnd, this.$refs.md.d_value.length);
                this.$refs.md.d_value = subStart + '\n' + linkImg + '\n' +  subEnd;
                // Restore the content of the pop-up window and close the pop-up window
                this.mdImgDesc = this.mdImgUrl = ''
                this.mdDialogVisible = false
            },
        },
    })
</script>
